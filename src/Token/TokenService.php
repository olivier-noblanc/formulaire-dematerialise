<?php

declare(strict_types=1);

namespace App\Token;

use App\Audit\AuditLogService;
use App\Auth\AuthService;
use App\Core\App;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Mail\MailService;
use App\Repository\DelegationRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;

/**
 * Service de gestion des tokens de validation.
 *
 * Extrait de lib/tokens.php — régénération, annulation, rappel, délégation.
 * Les fonctions globales dans lib/tokens.php délèguent maintenant ici.
 *
 * Le paramètre $database est conservé pour la compatibilité ascendante
 * (bootstrap, tests) mais n'est plus utilisé directement — tout accès DB
 * passe par les repositories injectés.
 */
final readonly class TokenService
{
    public TokenRepository $tokenRepository;
    public DelegationRepository $delegationRepository;

    public function __construct(
        private SettingsService $settingsService,
        private AuthService $authService,
        private AuditLogService $auditLogService,
        private MailService $mailService,
        private SubmissionRepository $submissionRepository,
        ?TokenRepository $tokenRepository = null,
        ?DelegationRepository $delegationRepository = null
    ) {
        $app = App::getInstance();
        $this->tokenRepository = $tokenRepository ?? $app->get(TokenRepository::class);
        $this->delegationRepository = $delegationRepository ?? $app->get(DelegationRepository::class);
    }

    /**
     * Régénère un token expiré pour un validateur (admin uniquement).
     */
    /** @return array{success: bool, message: string} */
    public function regenerate(string $oldTokenId): array
    {
        if (!$this->authService->isAdmin()) {
            $this->auditLogService->log('access_denied', 'token:' . $oldTokenId, 'Tentative de régénération de token non autorisée');
            return ['success' => false, 'message' => 'Accès refusé. Seul un administrateur peut régénérer un token.'];
        }

        $old = $this->tokenRepository->findForRegenerate($oldTokenId);

        if ($old === null) {
            return ['success' => false, 'message' => 'Token introuvable.'];
        }
        if ($old['done_at'] !== null) {
            return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
        }
        if ($old['sub_status'] !== SubmissionStatus::EnCours->value) {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        // Marquer l'ancien token comme traité (invalidé) + créer le nouveau — transactionnellement
        $newToken = generate_token();
        $expireDays = (int) $this->settingsService->get('token_expire_days', '30');
        $expiresAt_ts = strtotime("+{$expireDays} days");
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresAt_ts !== false ? $expiresAt_ts : time());
        $now = gmdate('Y-m-d H:i:s');
        $newTokenRowId = generate_uuid();

        $this->tokenRepository->beginTransaction();
        try {
            $this->tokenRepository->markDoneAndInvalidatedById($oldTokenId, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'));

            $this->tokenRepository->insertToken($newTokenRowId, $old['submission_id'], $old['step_id'], $old['email'], $newToken, $now, $expiresAt);

            $this->tokenRepository->commit();
        } catch (\Throwable $e) {
            $this->tokenRepository->rollBack();
            throw $e;
        }

        // Envoyer le nouveau lien par email
        $submission = App::workflow()->getSubmissionWithFormLabel($old['submission_id']);

        $stepLabel = $this->tokenRepository->findStepLabelByStepId($old['step_id']);

        if ($submission !== null && $stepLabel !== null) {
            $subject = '[Renvoi] ' . ($submission['form_label'] ?? '') . ' — ' . $stepLabel;
            $this->mailService->send($old['email'], $subject, App::mail()->buildMailHtml($submission, $stepLabel, $newToken));
        }

        $this->auditLogService->log('token_regenerate', 'token:' . $oldTokenId, 'Token régénéré pour ' . $old['email'] . ', nouveau token créé');

        return [
            'success' => true,
            'message' => 'Nouveau lien de validation envoyé à ' . $old['email'],
        ];
    }

    /**
     * Annule une soumission en cours.
     */
    /** @return array{success: bool, message: string} */
    public function cancel(string $submissionId, string $cancelledBy = ''): array
    {
        $caller = $cancelledBy !== '' ? $cancelledBy : $this->authService->getUser();
        $callerIsAdmin = $this->authService->isAdmin();

        $submission = App::workflow()->getSubmissionWithFormLabel($submissionId);

        if (!((bool)$submission)) {
            return ['success' => false, 'message' => 'Soumission introuvable.'];
        }
        if ($submission['status'] !== SubmissionStatus::EnCours->value) {
            return ['success' => false, 'message' => 'Seules les soumissions en cours peuvent être annulées.'];
        }

        if (!$callerIsAdmin && strtolower($submission['submitted_by']) !== strtolower($caller)) {
            $this->auditLogService->log('access_denied', 'submission:' . $submissionId, 'Tentative d\'annulation non autorisée par ' . $caller);
            return ['success' => false, 'message' => 'Vous n\'êtes pas autorisé à annuler cette soumission.'];
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->tokenRepository->beginTransaction();
        try {
            $this->submissionRepository->closeWithStatus($submissionId, $now, SubmissionStatus::Annule->value);

            $this->tokenRepository->invalidateActiveBySubmission($submissionId, $now);

            $appended = $this->submissionRepository->appendToDataJson($submissionId, function (array $data) use ($now, $cancelledBy): array {
                if (!isset($data['validations'])) {
                    $data['validations'] = [];
                }
                $data['validations'][] = [
                    'step_label' => 'Annulation',
                    'email' => $cancelledBy !== '' ? $cancelledBy : 'system',
                    'action' => ValidationAction::Annule->value,
                    'commentaire' => 'Soumission annulée',
                    'date' => $now,
                ];
                return $data;
            });
            // B8 fix : vérifier le retour — si l'optimistic locking a échoué 3x, la
            // data JSON n'a pas été mise à jour mais les tokens et submissions le sont.
            // Rollback pour rester cohérent (règle AGENTS.md #9 : surface l'échec).
            if (!$appended) {
                $this->tokenRepository->rollBack();
                $this->auditLogService->log(
                    'cancel_data_append_failed',
                    'submission:' . $submissionId,
                    'Échec appendToDataJson (conflit optimistic locking 3x) pendant cancel()',
                    $cancelledBy
                );
                return ['success' => false, 'message' => 'Conflit de mise à jour de la soumission. Réessayez.'];
            }

            $this->tokenRepository->commit();
        } catch (\Throwable $e) {
            $this->tokenRepository->rollBack();
            throw $e;
        }

        // Notifier l'agent
        $agentEmail = $submission['submitted_by'] ?? '';
        if ($agentEmail !== '' && $agentEmail !== '0' && filter_var($agentEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $subject = 'Demande annulée — ' . ($submission['form_label'] ?? \App\Render\NavigationRenderer::getAppName());
            $bodyHtml = '<h2 style="color:#b45309;">Demande annulée</h2>'
                . '<p>Votre demande <strong>' . \App\Core\App::html()->escape($submission['form_label'] ?? '') . '</strong> a été annulée.</p>';
            $this->mailService->send($agentEmail, $subject, App::mail()->renderEmailTemplate('Demande annulée', $bodyHtml));
        }

        $this->auditLogService->log('submission_cancel', 'submission:' . $submissionId, 'Soumission annulée', $cancelledBy);

        return ['success' => true, 'message' => 'Soumission annulée avec succès.'];
    }

    /**
     * Envoie un rappel manuel pour un token en attente.
     */
    /** @return array{success: bool, message: string} */
    public function remind(string $tokenId): array
    {
        $tok = App::workflow()->getTokenByIdWithContext($tokenId);

        if ($tok === null) {
            return ['success' => false, 'message' => 'Token introuvable.'];
        }
        if ($tok['done_at'] !== null) {
            return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
        }
        if ($tok['status'] !== SubmissionStatus::EnCours->value) {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        $stepLabel = $tok['step_label'] ?? 'Validation requise';
        $newCount = (int) $tok['relance_count'] + 1;
        $relanceMax = (int) $this->settingsService->get('relance_max', '3');

        if ($newCount > $relanceMax) {
            return ['success' => false, 'message' => 'Maximum de rappels atteint (' . $relanceMax . ').'];
        }

        $submission = [
            'data' => $tok['data'],
            'form_label' => $tok['form_label'],
        ];
        $subject = '[Rappel] ' . $tok['form_label'] . ' — ' . $stepLabel;
        if ($newCount > 1) {
            $subject = '[Rappel ' . $newCount . '/' . $relanceMax . '] ' . $tok['form_label'] . ' — ' . $stepLabel;
        }

        $mailBody = App::mail()->buildMailHtml($submission, $stepLabel, $tok['token']);
        $rappelNotice = '<div style="background:#fff3e0;border:1px solid #b45309;border-radius:4px;padding:12px;margin-bottom:16px;">
            <strong>Rappel :</strong> Cette demande est toujours en attente de votre validation.
            <br>Ceci est le rappel n°' . $newCount . ' sur un maximum de ' . $relanceMax . '.
        </div>';
        $mailBody = str_replace('<h2 style="color:#003189;">', $rappelNotice . '<h2 style="color:#003189;">', $mailBody);

        $mailSent = $this->mailService->send($tok['email'], $subject, $mailBody);

        if ($mailSent) {
            // B7 fix : ajouter WHERE done_at IS NULL pour ne pas incrémenter
            // relance_count sur un token qui aurait été traité entre-temps (race
            // condition entre la lecture de $tok et l'UPDATE). Vérifier rowCount()
            // pour ne pas logger un rappel qui n'a pas réellement mis à jour la DB.
            $rowCount = $this->tokenRepository->updateRelanceCountIfPending($tokenId, $newCount, gmdate('Y-m-d H:i:s'));
            if ($rowCount === 0) {
                // Token traité entre-temps (validation/refus concurrent). On ne log
                // pas un rappel fantôme — l'email a été envoyé mais l'état a changé.
                return ['success' => false, 'message' => 'Ce token vient d\'être traité. Le rappel n\'est plus pertinent.'];
            }
            $this->auditLogService->log('manual_remind', 'token:' . $tokenId, 'Rappel manuel envoyé à ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ')');
            return ['success' => true, 'message' => 'Rappel envoyé à ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ')'];
        }

        return ['success' => false, 'message' => 'Erreur lors de l\'envoi de l\'email à ' . $tok['email'] . '. Vérifiez la configuration SMTP.'];
    }

    /**
     * Délègue un token de validation à un autre validateur.
     */
    /** @return array{success: bool, message: string} */
    public function delegate(string $tokenId, string $toEmail, string $reason = ''): array
    {
        $tok = App::workflow()->getTokenByIdWithContext($tokenId);

        if ($tok === null) {
            return ['success' => false, 'message' => 'Token introuvable.'];
        }
        if ($tok['done_at'] !== null) {
            return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
        }
        if ($tok['status'] !== SubmissionStatus::EnCours->value) {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        $toEmail = strtolower(trim($toEmail));
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'message' => 'Adresse email invalide.'];
        }
        if ($toEmail === $tok['email']) {
            return ['success' => false, 'message' => 'Vous ne pouvez pas déléguer à vous-même.'];
        }

        if ($this->tokenRepository->hasPendingDuplicate($tok['submission_id'], $tok['step_id'], $toEmail)) {
            return ['success' => false, 'message' => 'Un token de validation est déjà actif pour ' . $toEmail . ' sur cette étape.'];
        }

        $newToken = generate_token();
        $expireDays = (int) $this->settingsService->get('token_expire_days', '30');
        $expiresAt_ts = strtotime("+{$expireDays} days");
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresAt_ts !== false ? $expiresAt_ts : time());
        $now = gmdate('Y-m-d H:i:s');
        $newTokenRowId = generate_uuid();
        $delegationId = generate_uuid();

        $this->tokenRepository->beginTransaction();
        try {
            // B6 fix : ajouter WHERE done_at IS NULL AND invalidated_at IS NULL
            // pour ne pas invalider un token qui aurait été traité entre la lecture
            // de $tok et l'UPDATE (race condition). Vérifier rowCount() pour
            // détecter le cas et rollback.
            $rowCount = $this->tokenRepository->tryInvalidateForDelegation($tokenId);
            if ($rowCount === 0) {
                // Token traité ou invalidé entre-temps (validation/refus/régénération
                // concurrent). On rollback et informe l'utilisateur.
                $this->tokenRepository->rollBack();
                return ['success' => false, 'message' => 'Ce token vient d\'être traité ou invalidé. La délégation n\'est plus possible.'];
            }

            $this->tokenRepository->insertToken($newTokenRowId, $tok['submission_id'], $tok['step_id'], $toEmail, $newToken, $now, $expiresAt);

            // B12 fix : utiliser gmdate() (PHP UTC) plutôt que datetime('now')
            // (SQLite UTC) pour que delegated_at soit calculé dans le même
            // référentiel que le reste de l'app (relance_at, done_at, etc.).
            // Avant : delegated_at venait de SQLite, les autres colonnes de PHP —
            // différence potentielle de 1s entre les deux appels.
            $this->delegationRepository->insertDelegation($delegationId, $tokenId, $tok['email'], $toEmail, $reason, $now, $newTokenRowId);

            $this->tokenRepository->commit();
        } catch (\Throwable $e) {
            $this->tokenRepository->rollBack();
            throw $e;
        }

        $stepLabel = $tok['step_label'] ?? 'Validation requise';
        $submission = [
            'data' => $tok['data'],
            'form_label' => $tok['form_label'],
        ];

        $subject = '[Délégation] ' . $tok['form_label'] . ' — ' . $stepLabel;
        $mailBody = App::mail()->buildMailHtml($submission, $stepLabel, $newToken);

        $delegationNotice = '<div style="background:#e8eaf6;border:1px solid #003189;border-radius:4px;padding:12px;margin-bottom:16px;">
            <strong>Délégation :</strong> Cette validation vous a été déléguée par <strong>' . App::html()->displayUser($tok['email']) . '</strong>.
            ' . ($reason === '' || $reason === '0' ? '' : '<br><em>Motif : ' . \App\Core\App::html()->escape($reason) . '</em>') . '
        </div>';
        $mailBody = str_replace('<h2 style="color:#003189;">', $delegationNotice . '<h2 style="color:#003189;">', $mailBody);

        $this->mailService->send($toEmail, $subject, $mailBody);

        $confirmSubject = 'Délégation confirmée — ' . $tok['form_label'];
        $confirmBodyHtml = '<h2 style="color:#003189;">Délégation confirmée</h2>'
            . '<p>Votre validation pour <strong>' . \App\Core\App::html()->escape($tok['form_label']) . '</strong> (étape ' . \App\Core\App::html()->escape($stepLabel) . ') a été déléguée à <strong>' . App::html()->displayUser($toEmail) . '</strong>.</p>'
            . '<p>Vous n\'avez plus besoin d\'effectuer cette validation.</p>';
        $this->mailService->send($tok['email'], $confirmSubject, App::mail()->renderEmailTemplate('Délégation confirmée', $confirmBodyHtml));

        $this->auditLogService->log('token_delegate', 'token:' . $tokenId, 'Token délégué de ' . $tok['email'] . ' à ' . $toEmail . ($reason !== '' && $reason !== '0' ? ' — Motif : ' . $reason : ''));

        return ['success' => true, 'message' => 'Validation déléguée à ' . $toEmail . '. Un email lui a été envoyé.'];
    }
}
