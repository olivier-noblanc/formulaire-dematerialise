<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Forms\FieldService;
use App\Mail\MailService;
use App\Repository\SubmissionRepository;
use App\Settings\SettingsService;

/**
 * Moteur de workflow — tokens, steps, validation.
 */
final readonly class WorkflowEngine
{
    public function __construct(private Database $database, private SettingsService $settingsService, private MailService $mailService, private FieldService $fieldService, private ConditionEvaluator $conditionEvaluator, private SubmissionRepository $submissionRepository) {}

    /**
     * @return array{
     *   id: string,
     *   submission_id: string,
     *   step_id: string,
     *   email: string,
     *   token: string,
     *   sent_at: string,
     *   done_at: string|null,
     *   relance_at: string|null,
     *   expires_at: string|null,
     *   relance_count: int,
     *   invalidated_at: string|null,
     *   action: string|null,
     *   step_label: string,
     *   form_id: string,
     *   form_label: string,
     *   data: string,
     *   closed_at: string|null,
     *   status: string,
     *   submitted_by: string
     * }|null
     */
    public function getTokenWithContext(string $tokenValue): ?array
    {
        // CS-05 (audit 2026-07-26) : factorisé via fetchTokenByCondition()
        // pour éliminer la duplication SQL/PHPDoc avec getTokenByIdWithContext().
        return $this->fetchTokenByCondition('t.token = ?', [$tokenValue]);
    }

    /**
     * @return array{
     *   id: string,
     *   submission_id: string,
     *   step_id: string,
     *   email: string,
     *   token: string,
     *   sent_at: string,
     *   done_at: string|null,
     *   relance_at: string|null,
     *   expires_at: string|null,
     *   relance_count: int,
     *   invalidated_at: string|null,
     *   action: string|null,
     *   step_label: string,
     *   form_id: string,
     *   form_label: string,
     *   data: string,
     *   closed_at: string|null,
     *   status: string,
     *   submitted_by: string
     * }|null
     */
    public function getTokenByIdWithContext(string $tokenId): ?array
    {
        // CS-05 : factorisé via fetchTokenByCondition()
        return $this->fetchTokenByCondition('t.id = ?', [$tokenId]);
    }

    /**
     * Helper privé mutualisant la requête SQL + la PHPDoc shape pour
     * getTokenWithContext() et getTokenByIdWithContext(). Seul le WHERE
     * change entre les deux.
     *
     * @param list<string> $params
     * @return array{
     *   id: string,
     *   submission_id: string,
     *   step_id: string,
     *   email: string,
     *   token: string,
     *   sent_at: string,
     *   done_at: string|null,
     *   relance_at: string|null,
     *   expires_at: string|null,
     *   relance_count: int,
     *   invalidated_at: string|null,
     *   action: string|null,
     *   step_label: string,
     *   form_id: string,
     *   form_label: string,
     *   data: string,
     *   closed_at: string|null,
     *   status: string,
     *   submitted_by: string
     * }|null
     */
    private function fetchTokenByCondition(string $whereClause, array $params): ?array
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare("
            SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at,
                   t.done_at, t.relance_at, t.expires_at, t.relance_count, t.invalidated_at, t.action,
                   st.label as step_label, s.form_id,
                   f.label as form_label, s.data, s.closed_at, s.status,
                   s.submitted_by
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE {$whereClause}
        ");
        $stmt->execute($params);
        /** @var array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, invalidated_at: string|null, action: string|null, step_label: string, form_id: string, form_label: string, data: string, closed_at: string|null, status: string, submitted_by: string}|false $result */
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /**
     * @return array<int, array{
     *   step_id: string,
     *   step_label: string,
     *   ordre: int,
     *   actif: int,
     *   condition: string,
     *   recipient_emails: string
     * }>
     */
    public function getWorkflowSteps(string $formId): array
    {
        $pdo = $this->database->getPdo();
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
        /** @var array<int, array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $result */
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * @return array{
     *   id: string,
     *   form_id: string,
     *   data: string,
     *   submitted_by: string,
     *   submitted_at: string,
     *   closed_at: string|null,
     *   status: string,
     *   admin_comment: string,
     *   rgpd_consent: int|null,
     *   form_label: string
     * }|null
     */
    public function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('
            SELECT s.id, s.form_id, s.data, s.submitted_by, s.submitted_at,
                   s.closed_at, s.status, s.admin_comment, s.rgpd_consent,
                   f.label as form_label
            FROM submissions s
            JOIN forms f ON f.id = s.form_id
            WHERE s.id = ?
        ');
        $stmt->execute([$submissionId]);
        // CS-12 fix (audit 2026-07-26) : la shape PHPDoc omettait rgpd_consent
        // bien que le SELECT l'inclue. Contourné par isset() dans
        // DownloadController:210, mais PHPStan ne pouvait pas détecter les
        // accès à des clés inexistantes. Shape maintenant complète.
        /** @var array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string}|false $result */
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /** @param array<string, mixed> $formData */
    public function resolveDynamicRecipient(string $recipient, array $formData, ?string $submissionId = null): string
    {
        // Cas spécial : {{owner}}
        if ($recipient === '{{owner}}') {
            if ($submissionId !== null) {
                $pdo = $this->database->getPdo();
                $formIdStmt = $pdo->prepare('SELECT form_id FROM submissions WHERE id = ?');
                $formIdStmt->execute([$submissionId]);
                $fid = (string) $formIdStmt->fetchColumn();
                if ($fid !== '') {
                    $owners = $this->getFormOwners($fid);
                    $firstOwnerEmail = $owners[0]['email'] ?? '';
                    if ($owners !== [] && filter_var($firstOwnerEmail, FILTER_VALIDATE_EMAIL)) {
                        return $firstOwnerEmail;
                    }
                    $adminEmail = $this->settingsService->get('admin_email');
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
                if (filter_var($resolved, FILTER_VALIDATE_EMAIL)) {
                    return $resolved;
                }
            }
            foreach ($formData as $key => $val) {
                if (strtolower((string) $key) === $fieldName && $val !== '' && $val !== null && $val !== '0') {
                    $resolved = trim((string) $val);
                    if (filter_var($resolved, FILTER_VALIDATE_EMAIL)) {
                        return $resolved;
                    }
                }
            }
            return $recipient;
        }

        return $recipient;
    }

    /** @return array<int, array{id: string, email: string, added_at: string}> */
    private function getFormOwners(string $formId): array
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('SELECT id, email, added_at FROM form_owners WHERE form_id = ? ORDER BY email');
        $stmt->execute([$formId]);
        /** @var array<int, array{id: string, email: string, added_at: string}> $result */
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $result;
    }

    public function advanceWorkflow(string $submissionId): void
    {
        // CS-01 (audit 2026-07-26) : god function de 160 lignes décomposée en 3 helpers
        // privés. Comportement inchangé, juste plus lisible et testable.
        $pdo = $this->database->getPdo();

        $submission = $this->getSubmissionWithFormLabel($submissionId);
        if (!$submission) {
            return;
        }
        if (!empty($submission['closed_at'])) {
            return;
        }

        $formId = (string) $submission['form_id'];
        $allSteps = $this->getWorkflowSteps($formId);

        // Grouper par ordre
        $byOrder = [];
        foreach ($allSteps as $step) {
            $byOrder[(int) $step['ordre']][] = $step;
        }
        ksort($byOrder);

        $now = gmdate('Y-m-d H:i:s');
        $expireDays = (int) $this->settingsService->get('token_expire_days', '30');
        $expiresAt_ts = strtotime("+{$expireDays} days");
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresAt_ts !== false ? $expiresAt_ts : time());

        // Transaction pour séquencer les lectures/écritures de tokens
        // et empêcher les doublons entre requêtes concurrentes
        $pdo->beginTransaction();
        $committed = false;
        try {
            // Tokens déjà créés (lu dans la transaction pour un snapshot cohérent)
            $tokenStmt = $pdo->prepare('SELECT step_id, done_at FROM tokens WHERE submission_id = ?');
            $tokenStmt->execute([$submissionId]);
            $tokensByStep = [];
            foreach ($tokenStmt->fetchAll(\PDO::FETCH_ASSOC) as $t) {
                $tokensByStep[(string) $t['step_id']][] = $t['done_at'];
            }

            // B-W1 fix (audit fonctionnel 2026-07-26) : si toutes les étapes de TOUS les
            // groupes ont leur condition=false (ou aucun recipient valide), on arrive
            // ici sans avoir créé aucun token — et le code clôturait la soumission comme
            // 'valide'. C'est un bug métier : une soumission sans aucune validation ne
            // devrait pas être marquée validée. On lève une exception (rollback auto via
            // le catch en bas) et on log pour diagnose.
            $totalTokensCreated = 0;
            foreach ($byOrder as $groupe) {
                $stepIds = array_column($groupe, 'step_id');
                $allStarted = count(array_intersect($stepIds, array_keys($tokensByStep))) === count($groupe);

                if (!$allStarted) {
                    // Créer les tokens manquants pour ce groupe. Si au moins un token a été
                    // créé, on s'arrête (en attendant leur validation).
                    $tokenCreated = $this->createTokensForGroup(
                        $groupe,
                        $tokensByStep,
                        $submission,
                        $submissionId,
                        $now,
                        $expiresAt,
                        $pdo
                    );
                    $totalTokensCreated += $tokenCreated ? 1 : 0;
                    if ($tokenCreated) {
                        $pdo->commit();
                        $committed = true;
                        return;
                    }
                    // Si aucun token créé pour ce groupe (conditions false ou recipients
                    // invalides), on ne le considère PAS comme complété. On sort en
                    // attendant qu'une action humaine corrige la config (condition,
                    // recipients) — la soumission reste en_cours, pas clôturée.
                    $pdo->commit();
                    $committed = true;
                    \App\Core\App::audit()->log(
                        'workflow_stalled',
                        'submission:' . $submissionId,
                        'Workflow bloqué : aucune étape du groupe ordre=' . ($groupe[0]['ordre'] ?? '?') . ' n\'a pu créer de token (conditions false ou recipients invalides). Soumission laissée en_cours — intervention admin requise.',
                        'WorkflowEngine'
                    );
                    return;
                }

                // Vérifier si toutes les étapes de cet ordre sont validées
                if (!$this->isGroupComplete($groupe, $tokensByStep)) {
                    $pdo->commit();
                    $committed = true;
                    return;
                }
            }

            // B-W1 : on n'arrive ici QUE si tous les groupes sont déjà validés
            // (tokens créés et done_at set pour tous). Si $totalTokensCreated === 0
            // et qu'on est ici, c'est que la boucle n'a créé aucun token ET tous les
            // groupes sont "complete" — ce qui est impossible sauf si la soumission
            // n'avait aucune étape active. On ne clôture QUE si des tokens existent.
            if ($tokensByStep === []) {
                // Aucune étape active dans le formulaire → on ne clôture PAS
                $pdo->commit();
                $committed = true;
                \App\Core\App::audit()->log(
                    'workflow_no_steps',
                    'submission:' . $submissionId,
                    'Aucune étape active trouvée pour ce formulaire — soumission laissée en_cours.',
                    'WorkflowEngine'
                );
                return;
            }

            // Toutes les étapes sont validées → clôturer
            $pdo->prepare('UPDATE submissions SET closed_at = ?, status = ? WHERE id = ?')
                ->execute([$now, SubmissionStatus::Valide->value, $submissionId]);

            $pdo->commit();
            $committed = true;

            // Notifier l'agent (hors transaction — side effect)
            $this->notifyAgentOfCompletion($submission);
        } catch (\Throwable $e) {
            if (!$committed && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Crée les tokens manquants pour un groupe d'étapes parallèles.
     *
     * @param list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $groupe
     * @param array<string, list<string|null>> $tokensByStep map step_id => [done_at values] (sera mutée in-place pour les nouveaux tokens)
     * @param array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string} $submission
     */
    private function createTokensForGroup(
        array $groupe,
        array &$tokensByStep,
        array $submission,
        string $submissionId,
        string $now,
        string $expiresAt,
        \PDO $pdo
    ): bool {
        $formData = json_decode($submission['data'] ?? '{}', true) ?? [];
        $validatorData = $this->getValidatorDataForEvaluation($submissionId);
        $tokenCreated = false;

        foreach ($groupe as $step) {
            // Étape déjà démarrée (a au moins un token) → ne pas créer de doublon
            if (isset($tokensByStep[$step['step_id']])) {
                continue;
            }

            // Évaluer la condition
            if (!$this->conditionEvaluator->evaluate(
                $step['condition'] ?? '',
                $validatorData
            )) {
                continue;
            }

            $rawEmails = explode('|', $step['recipient_emails'] ?? '');
            $hasRecipient = false;
            foreach ($rawEmails as $rawEmail) {
                $rawEmail = trim($rawEmail);
                if ($rawEmail === '' || $rawEmail === '0') {
                    continue;
                }

                $rawEmail = $this->resolveDynamicRecipient($rawEmail, $formData, $submissionId);
                if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                    error_log("Workflow: skipping invalid recipient '{$rawEmail}' for step {$step['step_id']}");
                    continue;
                }

                $hasRecipient = true;

                // Vérifier doublon
                $dupCheck = $pdo->prepare('SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL');
                $dupCheck->execute([$submissionId, $step['step_id'], $rawEmail]);
                if ($dupCheck->fetch()) {
                    continue;
                }

                $token = $this->generateToken();
                $tokenRowId = $this->generateUuid();
                try {
                    $pdo->prepare('INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$tokenRowId, $submissionId, $step['step_id'], $rawEmail, $token, $now, $expiresAt]);
                } catch (\PDOException $e) {
                    if ($e->getCode() === '23000') {
                        error_log("Workflow: duplicate token prevented for step {$step['step_id']}, email {$rawEmail}");
                        continue;
                    }
                    throw $e;
                }

                $subject = '[Action requise] ' . ($submission['form_label'] ?? '') . ' — ' . $step['step_label'];
                $mailSent = $this->mailService->send($rawEmail, $subject, $this->mailService->buildValidationEmail($submission, $step['step_label'], $token));
                if (!$mailSent) {
                    error_log("Workflow: mail failed for token $token to {$rawEmail}");
                }
                $tokenCreated = true;
                $tokensByStep[$step['step_id']][] = null; // done_at IS NULL pour le nouveau token
            }

            // Étape sans recipients valides — logger et ignorer (misconfiguration)
            if (!$hasRecipient && !in_array(trim($step['recipient_emails'] ?? ''), ['', '0'], true)) {
                error_log("Workflow: step {$step['step_id']} has condition true but no valid recipients — skipping");
            }
        }

        return $tokenCreated;
    }

    /**
     * Vérifie si toutes les étapes actives d'un groupe (ayant au moins un token)
     * sont validées (tous leurs tokens ont done_at IS NOT NULL).
     *
     * @param list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $groupe
     * @param array<string, list<string|null>> $tokensByStep
     */
    private function isGroupComplete(array $groupe, array $tokensByStep): bool
    {
        foreach ($groupe as $step) {
            // Étape sans token = condition false ou recipients invalides → pas concernée
            if (!isset($tokensByStep[$step['step_id']])) {
                continue;
            }
            $dones = $tokensByStep[$step['step_id']];
            if (!array_all($dones, fn(mixed $d) => $d !== null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Envoie l'email de notification à l'agent après clôture de la soumission.
     *
     * @param array{submitted_by: string, form_label: string} $submission
     */
    private function notifyAgentOfCompletion(array $submission): void
    {
        $agentEmail = $submission['submitted_by'] ?? '';
        if (filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Demande validée — ' . ($submission['form_label'] ?? '');
            $body = $this->mailService->renderEmailTemplate('Demande validée', '<p>Votre demande a été validée.</p>');
            $this->mailService->send($agentEmail, $subject, $body);
        }
    }

    /** @return array<string, string> */
    private function getValidatorDataForEvaluation(string $submissionId): array
    {
        $data = $this->fieldService->getValidatorData($submissionId);
        $result = [];
        foreach ($data as $vd) {
            $result[$vd['field_name'] ?? ''] = $vd['value'] ?? '';
        }
        return $result;
    }

    /**
     * Valide ou refuse un token.
     * @param string $doneBy Email du user logged-on qui a cliqué (v10.0.2)
     * @return array{
     *   status: string,
     *   data?: array{
     *     id: string,
     *     submission_id: string,
     *     step_id: string,
     *     email: string,
     *     token: string,
     *     sent_at: string,
     *     done_at: string|null,
     *     relance_at: string|null,
     *     expires_at: string|null,
     *     relance_count: int,
     *     step_label: string,
     *     form_id: string,
     *     form_label: string,
     *     data: string,
     *     closed_at: string|null,
     *     status: string,
     *     submitted_by: string
     *   },
     *   message?: string
     * }
     */
    public function validateToken(string $token, string $action = ValidationAction::Valider->value, string $comment = '', string $doneBy = ''): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['status' => 'invalid'];
        }
        if (!in_array($action, [ValidationAction::Valider->value, ValidationAction::Refuser->value], true)) {
            return ['status' => 'invalid', 'message' => 'Action non autorisée.'];
        }

        $pdo = $this->database->getPdo();
        $pdo->beginTransaction();

        $t = $this->getTokenWithContext($token);
        if ($t === null) {
            $pdo->rollBack();
            return ['status' => 'invalid'];
        }
        // B-V1 fix (audit fonctionnel 2026-07-26) : un token invalidé (par cancel,
        // regenerate ou delegate) ne doit pas pouvoir être validé même si done_at
        // est NULL. Avant, le check seul `!empty($t['done_at'])` laissait passer
        // les tokens invalidés — l'utilisateur voyait une page de validation
        // fonctionnelle alors que le token était mort.
        if (!empty($t['done_at']) || !empty($t['invalidated_at'])) {
            $pdo->rollBack();
            return ['status' => 'already_done', 'data' => $t];
        }
        if (!empty($t['closed_at'])) {
            $pdo->rollBack();
            return ['status' => 'closed', 'data' => $t];
        }

        if (!empty($t['expires_at'])) {
            // B1 fix (audit 2026-07-26) : les dates sont stockées en UTC (soit via
            // SQLite datetime('now'), soit via PHP gmdate()). strtotime() sans
            // fuseau explicite interprète la chaîne avec le fuseau serveur
            // (Europe/Paris en prod), causant un décalage de 1-2h : tokens
            // marqués expirés trop tôt. On force l'interprétation UTC en suffixant
            // la chaîne avec ' UTC' (notation reconnue par strtotime).
            // Même pattern que les fixes historiques #12 (alert_check.php) et
            // v10.22.0 (remind.php) — n'avait pas été appliqué ici.
            $expTs = strtotime($t['expires_at'] . ' UTC');
            if ($expTs !== false && $expTs < time()) {
                $pdo->rollBack();
                return ['status' => 'expired', 'data' => $t];
            }
        }

        $comment = mb_substr($comment, 0, 1000);

        if ($action === ValidationAction::Refuser->value) {
            $stmt = $pdo->prepare('UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL');
            $stmt->execute([gmdate('Y-m-d H:i:s'), $token]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return ['status' => 'already_done', 'data' => $t];
            }

            $pdo->prepare('UPDATE submissions SET closed_at = ?, status = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s'), SubmissionStatus::Refuse->value, $t['submission_id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE tokens SET done_at = ? WHERE token = ? AND done_at IS NULL');
            $stmt->execute([gmdate('Y-m-d H:i:s'), $token]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return ['status' => 'already_done', 'data' => $t];
            }
        }

        $validationEntry = [
            'step_label' => $t['step_label'],
            'email' => $t['email'],
            'done_by' => $doneBy,
            'action' => $action,
            'commentaire' => $comment,
            'date' => gmdate('Y-m-d H:i:s'),
        ];

        // B8 fix : appendToDataJson() fait de l'optimistic locking (WHERE data = old_json)
        // et peut retourner false si 3 conflits successifs. Avant, ce retour était
        // ignoré — l'audit_log disait 'validated' mais la data JSON n'avait pas la nouvelle
        // validation. Maintenant on rollback et on informe l'appelant.
        $appended = $this->submissionRepository->appendToDataJson($t['submission_id'], function (array $data) use ($validationEntry): array {
            $data['validations'][] = $validationEntry;
            return $data;
        });
        if (!$appended) {
            $pdo->rollBack();
            // Audit l'échec pour diagnose (règle AGENTS.md #9 : ne pas avaler silencieusement)
            \App\Core\App::audit()->log(
                'validation_data_append_failed',
                'submission:' . $t['submission_id'],
                'Échec appendToDataJson (conflit optimistic locking 3x) pour token ' . $token,
                $doneBy
            );
            return ['status' => 'data_conflict', 'data' => $t];
        }

        $pdo->commit();

        // Emails et advanceWorkflow APRES le commit (side effects hors transaction)
        if ($action === ValidationAction::Refuser->value) {
            $agentEmail = $t['submitted_by'] ?? '';
            if (filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
                $subject = 'Demande refusée — ' . ($t['form_label'] ?? '');
                $body = '<h2 style="color:#c0392b;">Demande refusée</h2>'
                    . '<p>Votre demande <strong>' . \App\Core\App::html()->escape($t['form_label'] ?? '') . '</strong> a été refusée à l\'étape <strong>' . \App\Core\App::html()->escape($t['step_label']) . '</strong>.</p>'
                    . ($comment === '' || $comment === '0' ? '' : '<p><strong>Motif :</strong> ' . \App\Core\App::html()->escape($comment) . '</p>');
                $this->mailService->send($agentEmail, $subject, $this->mailService->renderEmailTemplate('Demande refusée', $body));
            }
        } else {
            $this->advanceWorkflow($t['submission_id']);
        }

        $t['done_at'] = gmdate('Y-m-d H:i:s');
        return ['status' => 'ok', 'data' => $t];
    }

    public function hasActiveSubmissions(string $formId): int
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = ?');
        $stmt->execute([$formId, SubmissionStatus::EnCours->value]);
        return (int) $stmt->fetchColumn();
    }

    public function hasActiveStepSubmissions(string $stepId): int
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = ?
        ');
        $stmt->execute([$stepId, SubmissionStatus::EnCours->value]);
        return (int) $stmt->fetchColumn();
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
