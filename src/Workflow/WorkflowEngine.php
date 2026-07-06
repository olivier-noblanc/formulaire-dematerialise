<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\Database;
use App\Forms\FieldService;
use App\Settings\SettingsService;
use App\Mail\MailService;
use App\SubmissionStatus;
use App\Contract\WorkflowInterface;

/**
 * Moteur de workflow — tokens, steps, validation.
 */
final class WorkflowEngine implements WorkflowInterface
{
    private Database $db;
    private SettingsService $settings;
    private MailService $mail;
    private FieldService $fields;
    private ConditionEvaluator $conditions;

    public function __construct(
        Database $db,
        SettingsService $settings,
        MailService $mail,
        FieldService $fields,
        ConditionEvaluator $conditions
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->mail = $mail;
        $this->fields = $fields;
        $this->conditions = $conditions;
    }

    /** @return array<string, mixed>|null */
    public function getTokenWithContext(string $tokenValue): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT t.*, st.label as step_label, s.form_id,
                   f.label as form_label, s.data, s.closed_at, s.status,
                   s.submitted_by
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.token = ?
        ");
        $stmt->execute([$tokenValue]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return array<string, mixed>|null */
    public function getTokenByIdWithContext(string $tokenId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT t.*, st.label as step_label, s.form_id,
                   f.label as form_label, s.data, s.closed_at, s.status,
                   s.submitted_by
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.id = ?
        ");
        $stmt->execute([$tokenId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getWorkflowSteps(string $formId): array
    {
        static $cache = [];
        if (isset($cache[$formId])) return $cache[$formId];

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT st.id as step_id, st.label as step_label, st.ordre, st.actif, st.condition,
                   GROUP_CONCAT(sr.email, '|') as recipient_emails
            FROM steps st
            LEFT JOIN step_recipients sr ON sr.step_id = st.id
            WHERE st.form_id = ? AND st.actif = 1
            GROUP BY st.id
            ORDER BY st.ordre ASC, st.id ASC
        ");
        $stmt->execute([$formId]);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $cache[$formId] = $result;
        return $result;
    }

    /** @return array<string, mixed>|null */
    public function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT s.*, f.label as form_label
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE s.id = ?
        ");
        $stmt->execute([$submissionId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function resolveDynamicRecipient(string $recipient, array $formData, ?string $submissionId = null): string
    {
        // Cas spécial : {{owner}}
        if ($recipient === '{{owner}}') {
            if ($submissionId !== null) {
                $pdo = $this->db->getPdo();
                $formIdStmt = $pdo->prepare("SELECT form_id FROM submissions WHERE id = ?");
                $formIdStmt->execute([$submissionId]);
                $fid = (string) $formIdStmt->fetchColumn();
                if ($fid !== '') {
                    $owners = $this->getFormOwners($fid);
                    $firstOwnerEmail = $owners[0]['email'] ?? '';
                    if (!empty($owners) && filter_var($firstOwnerEmail, FILTER_VALIDATE_EMAIL)) {
                        return $firstOwnerEmail;
                    }
                    $adminEmail = $this->settings->get('admin_email');
                    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                        return $adminEmail;
                    }
                }
            }
            return $recipient;
        }

        if (preg_match('/^\{\{([a-z][a-z0-9_]*)\}\}$/', $recipient, $m)) {
            $fieldName = $m[1];
            if (isset($formData[$fieldName]) && !empty($formData[$fieldName])) {
                $resolved = trim((string) $formData[$fieldName]);
                if (filter_var($resolved, FILTER_VALIDATE_EMAIL)) return $resolved;
            }
            foreach ($formData as $key => $val) {
                if (strtolower($key) === $fieldName && !empty($val)) {
                    $resolved = trim((string) $val);
                    if (filter_var($resolved, FILTER_VALIDATE_EMAIL)) return $resolved;
                }
            }
            return $recipient;
        }

        return $recipient;
    }

    /** @return array<int, array<string, mixed>> */
    private function getFormOwners(string $formId): array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT id, email, added_at FROM form_owners WHERE form_id = ? ORDER BY email");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function advanceWorkflow(string $submissionId): void
    {
        $pdo = $this->db->getPdo();

        $submission = $this->getSubmissionWithFormLabel($submissionId);
        if (!$submission) return;
        if (!empty($submission['closed_at'])) return;

        $formId = (string) $submission['form_id'];
        $allSteps = $this->getWorkflowSteps($formId);

        // Grouper par ordre
        $byOrder = [];
        foreach ($allSteps as $step) {
            $byOrder[(int) $step['ordre']][] = $step;
        }
        ksort($byOrder);

        // Tokens déjà créés
        $tokenStmt = $pdo->prepare("SELECT step_id, done_at FROM tokens WHERE submission_id = ?");
        $tokenStmt->execute([$submissionId]);
        $tokensByStep = [];
        foreach ($tokenStmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            $tokensByStep[$t['step_id']][] = $t['done_at'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $expireDays = (int) $this->settings->get('token_expire_days', '30');
        $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$expireDays} days") ?: time());

        foreach ($byOrder as $ordre => $groupe) {
            $allStarted = array_reduce($groupe, function (bool $carry, array $step) use ($tokensByStep, $groupe): bool {
                $stepIds = array_column($groupe, 'step_id');
                $allStarted = count(array_intersect($stepIds, array_keys($tokensByStep))) === count($groupe);
                return $carry && $allStarted;
            }, true);

            if (!$allStarted) {
                $formData = json_decode($submission['data'] ?? '{}', true) ?: [];

                foreach ($groupe as $step) {
                    // Évaluer la condition
                    if (!$this->conditions->evaluate(
                        $step['condition'] ?? '',
                        $this->getValidatorDataForEvaluation($submissionId)
                    )) {
                        continue; // Skip cette étape
                    }

                    $rawEmails = explode('|', $step['recipient_emails'] ?? '');
                    foreach ($rawEmails as $email) {
                        $email = trim($email);
                        if (empty($email)) continue;

                        $email = $this->resolveDynamicRecipient($email, $formData, $submissionId);
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            error_log("Workflow: skipping invalid recipient '$email' for step {$step['step_id']}");
                            continue;
                        }

                        // Vérifier doublon
                        $dupCheck = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL");
                        $dupCheck->execute([$submissionId, $step['step_id'], $email]);
                        if ($dupCheck->fetch()) continue;

                        $token = $this->generateToken();
                        $tokenRowId = $this->generateUuid();
                        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$tokenRowId, $submissionId, $step['step_id'], $email, $token, $now, $expiresAt]);

                        $subject = '[Action requise] ' . ($submission['form_label'] ?? '') . ' — ' . $step['step_label'];
                        $mailSent = $this->mail->send($email, $subject, $this->mail->buildValidationEmail($submission, $step['step_label'], $token));
                        if (!$mailSent) {
                            error_log("Workflow: mail failed for token $token to $email");
                        }
                    }
                }
                return; // On a démarré cette étape — attendre validation
            }

            // Vérifier si toutes les étapes de cet ordre sont validées
            $allDone = true;
            foreach ($groupe as $step) {
                $dones = $tokensByStep[$step['step_id']] ?? [];
                if (empty($dones) || !array_all($dones, fn($d) => $d !== null)) {
                    $allDone = false;
                    break;
                }
            }

            if (!$allDone) return; // Attendre que toutes les étapes de cet ordre soient validées
        }

        // Toutes les étapes sont validées → clôturer
        $pdo->prepare("UPDATE submissions SET closed_at = ?, status = ? WHERE id = ?")
            ->execute([$now, SubmissionStatus::VALIDE->value, $submissionId]);

        // Notifier l'agent
        $agentEmail = $submission['submitted_by'] ?? '';
        if (filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Demande validée — ' . ($submission['form_label'] ?? '');
            $body = $this->mail->renderEmailTemplate('Demande validée', '<p>Votre demande a été validée.</p>');
            $this->mail->send($agentEmail, $subject, $body);
        }

        // Webhook
        $this->sendWebhook('workflow_complete', ['submission_id' => $submissionId]);
    }

    /** @return array<string, mixed> */
    private function getValidatorDataForEvaluation(string $submissionId): array
    {
        $data = $this->fields->getValidatorData($submissionId);
        $result = [];
        foreach ($data as $vd) {
            $result[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
        }
        return $result;
    }

    /**
     * Valide ou refuse un token.
     * @param string $doneBy Email du user logged-on qui a cliqué (v10.0.2)
     * @return array{status: string, data?: array<string, mixed>}
     */
    public function validateToken(string $token, string $action = 'valider', string $comment = '', string $doneBy = ''): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['status' => 'invalid'];
        }
        if (!in_array($action, ['valider', 'refuser'], true)) {
            return ['status' => 'invalid', 'message' => 'Action non autorisée.'];
        }

        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();

        $t = $this->getTokenWithContext($token);
        if (!$t) { $pdo->rollBack(); return ['status' => 'invalid']; }
        if (!empty($t['done_at'])) { $pdo->rollBack(); return ['status' => 'already_done', 'data' => $t]; }
        if (!empty($t['closed_at'])) { $pdo->rollBack(); return ['status' => 'closed', 'data' => $t]; }

        if (!empty($t['expires_at'])) {
            $expTs = strtotime($t['expires_at']);
            if ($expTs !== false && $expTs < time()) {
                $pdo->rollBack();
                return ['status' => 'expired', 'data' => $t];
            }
        }

        $data = json_decode((string) ($t['data'] ?? '{}'), true) ?: [];
        $comment = mb_substr($comment, 0, 1000);
        $data['validations'][] = [
            'step_label' => $t['step_label'],
            'email' => $t['email'],
            'done_by' => $doneBy,
            'action' => $action,
            'commentaire' => $comment,
            'date' => gmdate('Y-m-d H:i:s'),
        ];

        if ($action === 'refuser') {
            $stmt = $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL");
            $stmt->execute([gmdate('Y-m-d H:i:s'), $token]);
            if ($stmt->rowCount() === 0) { $pdo->rollBack(); return ['status' => 'already_done', 'data' => $t]; }

            $pdo->prepare("UPDATE submissions SET closed_at = ?, status = ? WHERE id = ?")
                ->execute([gmdate('Y-m-d H:i:s'), SubmissionStatus::REFUSE->value, $t['submission_id']]);

            $agentEmail = $t['submitted_by'] ?? '';
            if (filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
                $subject = 'Demande refusée — ' . ($t['form_label'] ?? '');
                $body = '<h2 style="color:#c0392b;">Demande refusée</h2>'
                    . '<p>Votre demande <strong>' . h($t['form_label'] ?? '') . '</strong> a été refusée à l\'étape <strong>' . h($t['step_label']) . '</strong>.</p>'
                    . (!empty($comment) ? '<p><strong>Motif :</strong> ' . h($comment) . '</p>' : '');
                $this->mail->send($agentEmail, $subject, $this->mail->renderEmailTemplate('Demande refusée', $body));
            }
        } else {
            $stmt = $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL");
            $stmt->execute([gmdate('Y-m-d H:i:s'), $token]);
            if ($stmt->rowCount() === 0) { $pdo->rollBack(); return ['status' => 'already_done', 'data' => $t]; }

            $this->advanceWorkflow($t['submission_id']);
        }

        $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
            ->execute([json_encode($data), $t['submission_id']]);

        $this->sendWebhook('token_validated', [
            'submission_id' => $t['submission_id'],
            'step_label' => $t['step_label'],
            'email' => $t['email'],
            'action' => $action,
        ]);

        $pdo->commit();
        $t['done_at'] = gmdate('Y-m-d H:i:s');
        return ['status' => 'ok', 'data' => $t];
    }

    public function hasActiveSubmissions(string $formId): int
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = ?");
        $stmt->execute([$formId, SubmissionStatus::EN_COURS->value]);
        return (int) $stmt->fetchColumn();
    }

    public function hasActiveStepSubmissions(string $stepId): int
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = ?
        ");
        $stmt->execute([$stepId, SubmissionStatus::EN_COURS->value]);
        return (int) $stmt->fetchColumn();
    }

    private function sendWebhook(string $event, array $data): void
    {
        $webhookUrl = $this->settings->get('webhook_url');
        $webhookEvents = $this->settings->get('webhook_events', '');
        if (empty($webhookUrl)) return;

        $events = array_map('trim', explode(',', $webhookEvents));
        if (!in_array('all', $events, true) && !in_array($event, $events, true)) return;

        $payload = json_encode([
            'event' => $event,
            'timestamp' => gmdate('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        if (function_exists('curl_init')) {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Event: ' . $event],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function generateToken(): string
    {
        return \generate_token();
    }

    private function generateUuid(): string
    {
        return \generate_uuid();
    }
}
