<?php
declare(strict_types=1);

namespace App\Token;

use App\Core\App;
use App\Core\Database;
use App\Settings\SettingsService;
use App\Auth\AuthService;
use App\Audit\AuditLogService;
use App\Mail\MailService;
use App\Workflow\WorkflowEngine;

/**
 * Service de gestion des tokens de validation.
 *
 * Extrait de lib/tokens.php — régénération, annulation, rappel, délégation.
 * Les fonctions globales dans lib/tokens.php délèguent maintenant ici.
 */
final class TokenService
{
    private Database $db;
    private SettingsService $settings;
    private AuthService $auth;
    private AuditLogService $audit;
    private MailService $mail;
    private WorkflowEngine $workflow;

    public function __construct(
        Database $db,
        SettingsService $settings,
        AuthService $auth,
        AuditLogService $audit,
        MailService $mail,
        WorkflowEngine $workflow
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->auth = $auth;
        $this->audit = $audit;
        $this->mail = $mail;
        $this->workflow = $workflow;
    }

    /**
     * Récupère les tokens d'une soumission avec les infos de l'étape associée.
     */
    public function getForSubmission(string $submissionId, array $extraFields = []): array
    {
        $allowedFields = ['t.id', 't.token', 't.relance_count', 't.relance_at', 't.expires_at', 't.sent_at'];
        if (!empty($extraFields)) {
            $extraFields = array_intersect($extraFields, $allowedFields);
        }
        $base = "t.email, t.done_at, t.sent_at, t.step_id, st.label, st.label as step_label, st.ordre";
        if (!empty($extraFields)) {
            $base = implode(', ', $extraFields) . ', ' . $base;
        }
        $stmt = $this->db->getPdo()->prepare("
            SELECT {$base}
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            WHERE t.submission_id = ?
            ORDER BY st.ordre ASC, st.label ASC
        ");
        $stmt->execute([$submissionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Régénère un token expiré pour un validateur (admin uniquement).
     */
    public function regenerate(string $oldTokenId): array
    {
        if (!$this->auth->isAdmin()) {
            $this->audit->log('access_denied', 'token:' . $oldTokenId, 'Tentative de régénération de token non autorisée');
            return ['success' => false, 'message' => 'Accès refusé. Seul un administrateur peut régénérer un token.'];
        }

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT t.*, s.status as sub_status
            FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.id = ?
        ");
        $stmt->execute([$oldTokenId]);
        $old = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$old) {
            return ['success' => false, 'message' => 'Token introuvable.'];
        }
        if ($old['done_at']) {
            return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
        }
        if ($old['sub_status'] !== 'en_cours') {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        // Marquer l'ancien token comme traité (invalidé)
        $pdo->prepare("UPDATE tokens SET done_at = ? WHERE id = ?")
            ->execute([gmdate('Y-m-d H:i:s'), $oldTokenId]);

        // Créer un nouveau token
        $newToken = generate_token();
        $expireDays = (int)$this->settings->get('token_expire_days', '30');
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$expireDays} days") ?: time());
        $now = gmdate('Y-m-d H:i:s');

        $newTokenRowId = generate_uuid();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$newTokenRowId, $old['submission_id'], $old['step_id'], $old['email'], $newToken, $now, $expiresAt]);

        // Envoyer le nouveau lien par email
        $submission = App::workflow()->getSubmissionWithFormLabel($old['submission_id']);

        $stepStmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
        $stepStmt->execute([$old['step_id']]);
        $step = $stepStmt->fetch(\PDO::FETCH_ASSOC);

        if ($submission && $step) {
            $subject = '[Renvoi] ' . ($submission['form_label'] ?? '') . ' — ' . ($step['label'] ?? '');
            $this->mail->send($old['email'], $subject, App::mail()->buildMailHtml($submission, $step['label'], $newToken));
        }

        $this->audit->log('token_regenerate', 'token:' . $oldTokenId, 'Token régénéré pour ' . $old['email'] . ', nouveau token créé');

        return [
            'success' => true,
            'message' => 'Nouveau lien de validation envoyé à ' . $old['email'],
        ];
    }

    /**
     * Annule une soumission en cours.
     */
    public function cancel(string $submissionId, string $cancelledBy = ''): array
    {
        $caller = $cancelledBy ?: $this->auth->getUser();
        $callerIsAdmin = $this->auth->isAdmin();

        $submission = App::workflow()->getSubmissionWithFormLabel($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'Soumission introuvable.'];
        }
        if ($submission['status'] !== 'en_cours') {
            return ['success' => false, 'message' => 'Seules les soumissions en cours peuvent être annulées.'];
        }

        if (!$callerIsAdmin && strtolower($submission['submitted_by']) !== strtolower($caller)) {
            $this->audit->log('access_denied', 'submission:' . $submissionId, 'Tentative d\'annulation non autorisée par ' . $caller);
            return ['success' => false, 'message' => 'Vous n\'êtes pas autorisé à annuler cette soumission.'];
        }

        $pdo = $this->db->getPdo();
        $now = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'annule' WHERE id = ?")
                ->execute([$now, $submissionId]);

            $pdo->prepare("UPDATE tokens SET done_at = ? WHERE submission_id = ? AND done_at IS NULL")
                ->execute([$now, $submissionId]);

            $data = json_decode($submission['data'], true) ?: [];
            if (!isset($data['validations'])) $data['validations'] = [];
            $data['validations'][] = [
                'step_label' => 'Annulation',
                'email' => $cancelledBy ?: 'system',
                'action' => 'refuser',
                'commentaire' => 'Soumission annulée',
                'date' => $now,
            ];
            $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
                ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $submissionId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Notifier l'agent
        $agentEmail = $submission['submitted_by'] ?? '';
        if (!empty($agentEmail) && filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Demande annulée — ' . ($submission['form_label'] ?? \App\Render\NavigationRenderer::getAppName());
            $bodyHtml = '<h2 style="color:#b45309;">Demande annulée</h2>'
                . '<p>Votre demande <strong>' . \App\Core\App::html()->escape($submission['form_label'] ?? '') . '</strong> a été annulée.</p>';
            $this->mail->send($agentEmail, $subject, App::mail()->renderEmailTemplate('Demande annulée', $bodyHtml));
        }

        $this->audit->log('submission_cancel', 'submission:' . $submissionId, 'Soumission annulée', $cancelledBy);

        return ['success' => true, 'message' => 'Soumission annulée avec succès.'];
    }

    /**
     * Envoie un rappel manuel pour un token en attente.
     */
    public function remind(string $tokenId): array
    {
        $tok = App::workflow()->getTokenByIdWithContext($tokenId);

        if (!$tok) {
            return ['success' => false, 'message' => 'Token introuvable.'];
        }
        if ($tok['done_at']) {
            return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
        }
        if ($tok['status'] !== 'en_cours') {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        $stepLabel = $tok['step_label'] ?? 'Validation requise';
        $newCount = (int)$tok['relance_count'] + 1;
        $relanceMax = (int)$this->settings->get('relance_max', '3');

        $this->db->getPdo()->prepare("UPDATE tokens SET relance_count = ?, relance_at = ? WHERE id = ?")
            ->execute([$newCount, gmdate('Y-m-d H:i:s'), $tokenId]);

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

        $mailSent = $this->mail->send($tok['email'], $subject, $mailBody);

        $this->audit->log('manual_remind', 'token:' . $tokenId, 'Rappel manuel envoyé à ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ')');

        if ($mailSent) {
            return ['success' => true, 'message' => 'Rappel envoyé à ' . $tok['email'] . ' (relance ' . $newCount . '/' . $relanceMax . ')'];
        } else {
            return ['success' => false, 'message' => 'Erreur lors de l\'envoi de l\'email à ' . $tok['email'] . '. Vérifiez la configuration SMTP.'];
        }
    }

    /**
     * Délègue un token de validation à un autre validateur.
     */
    public function delegate(string $tokenId, string $toEmail, string $reason = ''): array
    {
        $tok = App::workflow()->getTokenByIdWithContext($tokenId);

        if (!$tok) {
            return ['success' => false, 'message' => 'Token introuvable.'];
        }
        if ($tok['done_at']) {
            return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
        }
        if ($tok['status'] !== 'en_cours') {
            return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
        }

        $toEmail = strtolower(trim($toEmail));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Adresse email invalide.'];
        }
        if ($toEmail === $tok['email']) {
            return ['success' => false, 'message' => 'Vous ne pouvez pas déléguer à vous-même.'];
        }

        $pdo = $this->db->getPdo();

        $dupCheck = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL");
        $dupCheck->execute([$tok['submission_id'], $tok['step_id'], $toEmail]);
        if ($dupCheck->fetch()) {
            return ['success' => false, 'message' => 'Un token de validation est déjà actif pour ' . $toEmail . ' sur cette étape.'];
        }

        $pdo->prepare("UPDATE tokens SET done_at = datetime('now') WHERE id = ?")
            ->execute([$tokenId]);

        $newToken = generate_token();
        $expireDays = (int)$this->settings->get('token_expire_days', '30');
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$expireDays} days") ?: time());
        $now = gmdate('Y-m-d H:i:s');

        $newTokenRowId = generate_uuid();
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$newTokenRowId, $tok['submission_id'], $tok['step_id'], $toEmail, $newToken, $now, $expiresAt]);

        $delegationId = generate_uuid();
        $pdo->prepare("INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
            ->execute([$delegationId, $tokenId, $tok['email'], $toEmail, $reason, $newTokenRowId]);

        $stepLabel = $tok['step_label'] ?? 'Validation requise';
        $submission = [
            'data' => $tok['data'],
            'form_label' => $tok['form_label'],
        ];

        $subject = '[Délégation] ' . $tok['form_label'] . ' — ' . $stepLabel;
        $mailBody = App::mail()->buildMailHtml($submission, $stepLabel, $newToken);

        $delegationNotice = '<div style="background:#e8eaf6;border:1px solid #003189;border-radius:4px;padding:12px;margin-bottom:16px;">
            <strong>Délégation :</strong> Cette validation vous a été déléguée par <strong>' . App::html()->displayUser($tok['email']) . '</strong>.
            ' . (!empty($reason) ? '<br><em>Motif : ' . \App\Core\App::html()->escape($reason) . '</em>' : '') . '
        </div>';
        $mailBody = str_replace('<h2 style="color:#003189;">', $delegationNotice . '<h2 style="color:#003189;">', $mailBody);

        $this->mail->send($toEmail, $subject, $mailBody);

        $confirmSubject = 'Délégation confirmée — ' . $tok['form_label'];
        $confirmBodyHtml = '<h2 style="color:#003189;">Délégation confirmée</h2>'
            . '<p>Votre validation pour <strong>' . \App\Core\App::html()->escape($tok['form_label']) . '</strong> (étape ' . \App\Core\App::html()->escape($stepLabel) . ') a été déléguée à <strong>' . App::html()->displayUser($toEmail) . '</strong>.</p>'
            . '<p>Vous n\'avez plus besoin d\'effectuer cette validation.</p>';
        $this->mail->send($tok['email'], $confirmSubject, App::mail()->renderEmailTemplate('Délégation confirmée', $confirmBodyHtml));

        $this->audit->log('token_delegate', 'token:' . $tokenId, 'Token délégué de ' . $tok['email'] . ' à ' . $toEmail . ($reason ? ' — Motif : ' . $reason : ''));

        return ['success' => true, 'message' => 'Validation déléguée à ' . $toEmail . '. Un email lui a été envoyé.'];
    }

    /**
     * Récupère l'historique des délégations pour une soumission.
     */
    public function getDelegations(string $submissionId): array
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT d.*, t.step_id, st.label as step_label
            FROM delegations d
            JOIN tokens t ON t.id = d.token_id
            JOIN steps st ON st.id = t.step_id
            WHERE t.submission_id = ?
            ORDER BY d.delegated_at DESC
        ");
        $stmt->execute([$submissionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
