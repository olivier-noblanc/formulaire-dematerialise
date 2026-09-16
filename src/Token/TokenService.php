<?php

declare(strict_types=1);

namespace App\Token;

use App\Audit\AuditLogService;
use App\Auth\AuthService;
use App\Contract\MailInterface;
use App\Core\App;
use App\Enum\MailStatus;
use App\Enum\SubmissionField;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Repository\DelegationRepository;
use App\Repository\FormRepository;
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
    public FormRepository $formRepository;

    public function __construct(
        private SettingsService $settingsService,
        private AuthService $authService,
        private AuditLogService $auditLogService,
        private MailInterface $mailService,
        private SubmissionRepository $submissionRepository,
        ?TokenRepository $tokenRepository = null,
        ?DelegationRepository $delegationRepository = null,
        ?FormRepository $formRepository = null
    ) {
        $app = App::getInstance();
        $this->tokenRepository = $tokenRepository ?? $app->get(TokenRepository::class);
        $this->delegationRepository = $delegationRepository ?? $app->get(DelegationRepository::class);
        $this->formRepository = $formRepository ?? $app->get(FormRepository::class);
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
        if ($old['invalidated_at'] !== null) {
            return ['success' => false, 'message' => 'Ce token a été invalidé (délégué ou annulé) et ne peut pas être régénéré.'];
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
            // Protection race condition : l'UPDATE atomique échoue si le token
            // a été traité/invalidé entre la lecture de $old et l'UPDATE.
            $marked = $this->tokenRepository->markDoneAndInvalidatedById($oldTokenId, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'));
            if (!$marked) {
                $this->tokenRepository->rollBack();
                return ['success' => false, 'message' => 'Ce token vient d\'être traité ou invalidé, ou la soumission n\'est plus en cours. La régénération n\'est plus possible.'];
            }

            $this->tokenRepository->insertToken($newTokenRowId, $old['submission_id'], $old['step_id'], $old['email'], $newToken, $now, $expiresAt);

            $this->tokenRepository->commit();
        } catch (\Throwable $e) {
            $this->tokenRepository->rollBack();
            throw $e;
        }

        // Envoyer le nouveau lien par email
        $submission = App::workflow()->getSubmissionWithFormLabel($old['submission_id']);

        $stepLabel = $this->tokenRepository->findStepLabelByStepId($old['step_id']);

        $mailSent = false;
        if ($submission !== null && $stepLabel !== null) {
            $subject = '[Renvoi] ' . ($submission['form_label'] ?? '') . ' — ' . $stepLabel;
            $mailSent = $this->mailService->send($old['email'], $subject, App::mail()->buildMailHtml($submission, $stepLabel, $newToken));
        }

        $this->auditLogService->log('token_regenerate', 'token:' . $oldTokenId, 'Token régénéré pour ' . $old['email'] . ', nouveau token créé');

        return [
            'success' => true,
            'message' => $mailSent
                ? 'Nouveau lien de validation envoyé à ' . $old['email']
                : 'Nouveau lien de validation créé pour ' . $old['email'] . ', mais l\'email n\'a pas pu être envoyé. Vérifiez la configuration SMTP ou transmettez le nouveau lien manuellement.',
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
            // R3 (audit 2026-09-14) — CAS : closeWithStatus ne clôture que si la
            // soumission est encore en_cours et non clôturée. Un premier commit
            // gagne ; si une action concurrente (annulation/validation) a déjà
            // clôturé la soumission entre le check initial et l'UPDATE, on
            // rollback et on remonte un conflit explicite (pas de double clôture).
            if (!$this->submissionRepository->closeWithStatus($submissionId, $now, SubmissionStatus::Annule->value)) {
                $this->tokenRepository->rollBack();
                $this->auditLogService->log(
                    'cancel_conflict',
                    'submission:' . $submissionId,
                    'Annulation refusée : soumission déjà clôturée ou plus en cours (course concurrente)',
                    $cancelledBy
                );
                return ['success' => false, 'message' => 'Cette soumission vient d\'être clôturée par une autre action. Annulation impossible.'];
            }

            $this->tokenRepository->invalidateActiveBySubmission($submissionId, $now);

            $appended = $this->submissionRepository->appendToDataJson($submissionId, function (array $data) use ($now, $cancelledBy): array {
                $data[SubmissionField::VALIDATIONS->value] ??= [];
                $data[SubmissionField::VALIDATIONS->value][] = [
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
        $mailSent = false;
        $agentEmail = $submission['submitted_by'] ?? '';
        if ($agentEmail !== '' && $agentEmail !== '0' && filter_var($agentEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $subject = 'Demande annulée — ' . ($submission['form_label'] ?? \App\Render\NavigationRenderer::getAppName());
            $bodyHtml = '<h2 style="color:#b45309;">Demande annulée</h2>'
                . '<p>Votre demande <strong>' . \App\Core\App::html()->escape($submission['form_label'] ?? '') . '</strong> a été annulée.</p>';
            $mailSent = $this->mailService->send($agentEmail, $subject, App::mail()->renderEmailTemplate('Demande annulée', $bodyHtml));
        }

        $this->auditLogService->log('submission_cancel', 'submission:' . $submissionId, 'Soumission annulée', $cancelledBy);

        return [
            'success' => true,
            'message' => $mailSent
                ? 'Soumission annulée avec succès.'
                : 'Soumission annulée avec succès, mais l\'email de notification à l\'agent n\'a pas pu être envoyé.',
        ];
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
        if ($tok['invalidated_at'] !== null) {
            return ['success' => false, 'message' => 'Ce token a été invalidé (délégué ou annulé).'];
        }
        if ($tok['expires_at'] !== null) {
            // expires_at est stocké en UTC (gmdate) — comparaison UTC explicite.
            $expires = new \DateTimeImmutable($tok['expires_at'], new \DateTimeZone('UTC'));
            $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($expires < $nowUtc) {
                return ['success' => false, 'message' => 'Ce token est expiré — demandez sa régénération à un administrateur.'];
            }
        }
        if ($tok['status'] !== SubmissionStatus::EnCours->value) {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        $stepLabel = $tok['step_label'] ?? 'Validation requise';
        $previousCount = (int) $tok['relance_count'];
        $newCount = $previousCount + 1;
        $relanceMax = (int) ($this->formRepository->getRelanceConfig($tok['form_id'])['relance_max'] ?? 3);

        if ($newCount > $relanceMax) {
            return ['success' => false, 'message' => 'Maximum de rappels atteint (' . $relanceMax . ').'];
        }

        // R2 (audit 2026-09-14) — revendication atomique (CAS) du créneau de
        // rappel AVANT l'envoi SMTP : deux rappels manuels concurrents
        // (double-clic admin, deux onglets) lisaient le même relance_count,
        // envoyaient chacun un mail et écrasaient le compteur (plafond
        // relance_max contourné, rappel fantôme). Seul le gagnant du CAS
        // envoie ; le perdant s'abstient avec un message de conflit.
        // tryClaimRelance filtre aussi done_at/invalidated_at : un token traité
        // ou invalidé entre la lecture et la revendication n'est pas relancé.
        $now = gmdate('Y-m-d H:i:s');
        if ($this->tokenRepository->tryClaimRelance($tokenId, $previousCount, $now) === 0) {
            return ['success' => false, 'message' => 'Ce token vient d\'être traité, invalidé, ou un rappel est déjà en cours.'];
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

        $sendResult = $this->sendRelanceMail($tok['email'], $subject, $mailBody);

        // BUG2 — seul un refus DÉFINITIF (`blocked` : adresse destinataire
        // invalide, config SMTP/From absente) n'est jamais rejoué par l'outbox :
        // on libère alors le créneau revendiqué (CAS inverse) pour ne pas
        // compter un rappel impossible ni bloquer le suivant. Le CAS sur
        // relance_count évite de raboter une relance ultérieure.
        //
        // Tout autre échec (`error` : SMTP injoignable/timeout, write-ahead
        // indisponible) est persisté ou rejouable par le worker d'outbox : le
        // créneau DOIT rester revendiqué, sinon le rejeu enverrait l'email alors
        // que relance_count a été rabattu — contournement du plafond relance_max.
        if ($sendResult['status'] === MailStatus::Blocked) {
            $this->tokenRepository->releaseRelanceClaim($tokenId, $newCount, $previousCount, $tok['relance_at']);
            return [
                'success' => false,
                'message' => 'Rappel non envoyé à ' . $tok['email'] . ' : ' . $sendResult['error'],
            ];
        }

        if (!$sendResult['success']) {
            // Erreur réessayable / write-ahead : l'envoi est (ou sera) repris
            // par l'outbox. Le créneau reste revendiqué et la relance comptée.
            $this->auditLogService->log(
                'manual_remind',
                'token:' . $tokenId,
                'Rappel mis en file d\'attente pour ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ') — ' . $sendResult['error']
            );
            return [
                'success' => false,
                'message' => 'Rappel mis en file d\'attente pour ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ') : l\'envoi sera retenté automatiquement.',
            ];
        }

        $this->auditLogService->log('manual_remind', 'token:' . $tokenId, 'Rappel manuel envoyé à ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ')');
        return ['success' => true, 'message' => 'Rappel envoyé à ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ')'];
    }

    /**
     * Envoie l'email de rappel en privilégiant le contrat détaillé de
     * MailService (`sendDetailed`) pour connaître le STATUT outbox (write-ahead).
     *
     * Repli sur `send()` pour les implémentations de MailInterface qui
     * n'exposent pas `sendDetailed` (doubles de test) : un booléen ne
     * distinguant pas un refus définitif d'un échec réessayable, un échec y est
     * classé `error` (claim conservé) — défaut conservateur qui empêche un
     * rejeu de contourner `relance_max`.
     *
     * @return array{success: bool, error: string, status: MailStatus}
     */
    private function sendRelanceMail(string $to, string $subject, string $body): array
    {
        $mailer = $this->mailService;
        if (method_exists($mailer, 'sendDetailed')) {
            /** @var array{success: bool, error: string, smtp_log: string, status: string} $detailed */
            $detailed = $mailer->sendDetailed($to, $subject, $body);
            return [
                'success' => $detailed['success'],
                'error' => $detailed['error'],
                'status' => MailStatus::tryFrom($detailed['status']) ?? MailStatus::Error,
            ];
        }

        $sent = $mailer->send($to, $subject, $body);
        return [
            'success' => $sent,
            'error' => $sent ? '' : 'Échec d\'envoi (statut indisponible)',
            'status' => $sent ? MailStatus::Sent : MailStatus::Error,
        ];
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
        if ($tok['invalidated_at'] !== null) {
            return ['success' => false, 'message' => 'Ce token a été invalidé — la délégation n\'est plus possible.'];
        }
        if ($tok['expires_at'] !== null) {
            // B4 : expires_at est stocké en UTC (gmdate) — comparaison UTC explicite.
            $expires = new \DateTimeImmutable($tok['expires_at'], new \DateTimeZone('UTC'));
            $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($expires < $nowUtc) {
                return ['success' => false, 'message' => 'Ce token est expiré — la délégation n\'est plus possible. Demandez sa régénération à un administrateur.'];
            }
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
                return ['success' => false, 'message' => 'Ce token vient d\'être traité ou invalidé, ou la soumission n\'est plus en cours. La délégation n\'est plus possible.'];
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

        $primarySent = $this->mailService->send($toEmail, $subject, $mailBody);

        $confirmSubject = 'Délégation confirmée — ' . $tok['form_label'];
        $confirmBodyHtml = '<h2 style="color:#003189;">Délégation confirmée</h2>'
            . '<p>Votre validation pour <strong>' . \App\Core\App::html()->escape($tok['form_label']) . '</strong> (étape ' . \App\Core\App::html()->escape($stepLabel) . ') a été déléguée à <strong>' . App::html()->displayUser($toEmail) . '</strong>.</p>'
            . '<p>Vous n\'avez plus besoin d\'effectuer cette validation.</p>';
        $confirmSent = $this->mailService->send($tok['email'], $confirmSubject, App::mail()->renderEmailTemplate('Délégation confirmée', $confirmBodyHtml));

        // Audit métier : tracer le sort réel des DEUX notifications, distinctement.
        // `mail_sent` = mail principal au délégataire (le lien, critique : sans lui
        // la validation déléguée est impossible) ; `confirm_mail_sent` = mail de
        // confirmation au validateur d'origine (informatif).
        $this->auditLogService->log(
            'token_delegate',
            'token:' . $tokenId,
            'Token délégué de ' . $tok['email'] . ' à ' . $toEmail
                . ($reason !== '' && $reason !== '0' ? ' — Motif : ' . $reason : '')
                . ' — mail_sent=' . ($primarySent ? '1' : '0')
                . ' confirm_mail_sent=' . ($confirmSent ? '1' : '0')
        );

        // La délégation DB est déjà commitée (ci-dessus) : on ne rapporte jamais
        // un échec global, uniquement le statut réel de chaque envoi. L'échec du
        // mail principal (délégataire) est signalé explicitement — le message doit
        // dire clairement que le délégataire n'a pas reçu le lien.
        $message = 'Validation déléguée à ' . $toEmail . '.';
        if ($primarySent && $confirmSent) {
            $message .= ' Un email lui a été envoyé.';
        } elseif (!$primarySent) {
            $message .= ' Attention : le lien n\'a pas pu être envoyé au délégataire — il ne l\'a pas reçu. Transmettez-le-lui manuellement ou demandez une régénération à un administrateur.';
            $message .= $confirmSent
                ? ' Le validateur d\'origine a bien été informé de la délégation.'
                : ' L\'email de confirmation au validateur d\'origine n\'a pas pu être envoyé non plus.';
        } else {
            // Le lien au délégataire est parti ; seule la confirmation a échoué.
            $message .= ' Un email lui a été envoyé. L\'email de confirmation au validateur d\'origine n\'a pas pu être envoyé.';
        }

        return ['success' => true, 'message' => $message];
    }
}
