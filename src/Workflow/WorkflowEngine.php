<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Enum\SubmissionStatus;
use App\Enum\ValidationAction;
use App\Forms\FieldService;
use App\Mail\MailService;
use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;

/**
 * Moteur de workflow — tokens, steps, validation.
 *
 * Tout accès DB passe par les repositories injectés ($tokenRepository,
 * $formRepository, $submissionRepository) ou résolus via App.
 */
final readonly class WorkflowEngine
{
    public TokenRepository $tokenRepository;
    public FormRepository $formRepository;

    public function __construct(
        private SettingsService $settingsService,
        private MailService $mailService,
        private FieldService $fieldService,
        private ConditionEvaluator $conditionEvaluator,
        private SubmissionRepository $submissionRepository,
        ?TokenRepository $tokenRepository = null,
        ?FormRepository $formRepository = null
    ) {
        $this->tokenRepository = $tokenRepository ?? \App\Core\App::getInstance()->get(TokenRepository::class);
        $this->formRepository = $formRepository ?? \App\Core\App::getInstance()->get(FormRepository::class);
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
        return $this->tokenRepository->findTokenWithContextByCondition($whereClause, $params);
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
        return $this->formRepository->findWorkflowStepsForEngine($formId);
    }

    /**
     * @return array{
     *   id: string,
     *   form_id: string,
     *   data: string,
     *   submitted_by: string,
     *   submitted_at: string|null,
     *   closed_at: string|null,
     *   status: string,
     *   admin_comment: string,
     *   rgpd_consent: int|null,
     *   form_label: string
     * }|null
     */
    public function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        return $this->submissionRepository->findWithFormLabelById($submissionId);
    }

    /** @param array<string, mixed> $formData */
    public function resolveDynamicRecipient(string $recipient, array $formData, ?string $submissionId = null): string
    {
        // Cas spécial : {{owner}}
        if ($recipient === '{{owner}}') {
            if ($submissionId !== null) {
                $fid = $this->submissionRepository->findFormIdById($submissionId);
                if ($fid !== null && $fid !== '') {
                    $owners = $this->formRepository->findOwnersByFormId($fid);
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

    public function advanceWorkflow(string $submissionId): void
    {
        // CS-01 (audit 2026-07-26) : god function de 160 lignes décomposée en 3 helpers
        // privés. Comportement inchangé, juste plus lisible et testable.
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
        // et empêcher les doublons entre requêtes concurrentes.
        // BaseRepository expose beginTransaction/commit/rollBack — les repos
        // partagent la même connexion PDO (Database singleton).
        $this->tokenRepository->beginTransaction();
        $committed = false;
        try {
            // Tokens déjà créés (lu dans la transaction pour un snapshot cohérent)
            $tokensByStep = [];
            foreach ($this->tokenRepository->findStepIdsAndDonesBySubmission($submissionId) as $t) {
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
                        $expiresAt
                    );
                    $totalTokensCreated += $tokenCreated ? 1 : 0;
                    if ($tokenCreated) {
                        $this->tokenRepository->commit();
                        $committed = true;
                        return;
                    }
                    // Si aucun token créé pour ce groupe (conditions false ou recipients
                    // invalides), on ne le considère PAS comme complété. On sort en
                    // attendant qu'une action humaine corrige la config (condition,
                    // recipients) — la soumission reste en_cours, pas clôturée.
                    $this->tokenRepository->commit();
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
                    $this->tokenRepository->commit();
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
                $this->tokenRepository->commit();
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
            $this->submissionRepository->closeWithStatus($submissionId, $now, SubmissionStatus::Valide->value);

            $this->tokenRepository->commit();
            $committed = true;

            // Notifier l'agent (hors transaction — side effect)
            $this->notifyAgentOfCompletion($submission);
        } catch (\Throwable $e) {
            if (!$committed && $this->tokenRepository->inTransaction()) {
                $this->tokenRepository->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Crée les tokens manquants pour un groupe d'étapes parallèles.
     *
     * @param list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $groupe
     * @param array<string, list<string|null>> $tokensByStep map step_id => [done_at values] (sera mutée in-place pour les nouveaux tokens)
     * @param array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, rgpd_consent: int|null, form_label: string} $submission
     */
    private function createTokensForGroup(
        array $groupe,
        array &$tokensByStep,
        array $submission,
        string $submissionId,
        string $now,
        string $expiresAt
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
                if ($this->tokenRepository->hasPendingDuplicate($submissionId, $step['step_id'], $rawEmail)) {
                    continue;
                }

                $token = $this->generateToken();
                $tokenRowId = $this->generateUuid();
                try {
                    $this->tokenRepository->insertToken($tokenRowId, $submissionId, $step['step_id'], $rawEmail, $token, $now, $expiresAt);
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

        $this->tokenRepository->beginTransaction();

        $t = $this->getTokenWithContext($token);
        if ($t === null) {
            $this->tokenRepository->rollBack();
            return ['status' => 'invalid'];
        }
        // B-V1 fix (audit fonctionnel 2026-07-26) : un token invalidé (par cancel,
        // regenerate ou delegate) ne doit pas pouvoir être validé même si done_at
        // est NULL. Avant, le check seul `!empty($t['done_at'])` laissait passer
        // les tokens invalidés — l'utilisateur voyait une page de validation
        // fonctionnelle alors que le token était mort.
        if (!empty($t['done_at']) || !empty($t['invalidated_at'])) {
            $this->tokenRepository->rollBack();
            return ['status' => 'already_done', 'data' => $t];
        }
        if (!empty($t['closed_at'])) {
            $this->tokenRepository->rollBack();
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
                $this->tokenRepository->rollBack();
                return ['status' => 'expired', 'data' => $t];
            }
        }

        $comment = mb_substr($comment, 0, 1000);

        if ($action === ValidationAction::Refuser->value) {
            $rowCount = $this->tokenRepository->markDoneByTokenValue($token, gmdate('Y-m-d H:i:s'));
            if ($rowCount === 0) {
                $this->tokenRepository->rollBack();
                return ['status' => 'already_done', 'data' => $t];
            }

            $this->submissionRepository->closeWithStatus($t['submission_id'], gmdate('Y-m-d H:i:s'), SubmissionStatus::Refuse->value);
        } else {
            $rowCount = $this->tokenRepository->markDoneByTokenValue($token, gmdate('Y-m-d H:i:s'));
            if ($rowCount === 0) {
                $this->tokenRepository->rollBack();
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
            $this->tokenRepository->rollBack();
            // Audit l'échec pour diagnose (règle AGENTS.md #9 : ne pas avaler silencieusement)
            \App\Core\App::audit()->log(
                'validation_data_append_failed',
                'submission:' . $t['submission_id'],
                'Échec appendToDataJson (conflit optimistic locking 3x) pour token ' . $token,
                $doneBy
            );
            return ['status' => 'data_conflict', 'data' => $t];
        }

        $this->tokenRepository->commit();

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
        return $this->submissionRepository->countActiveByFormAndStatus($formId, SubmissionStatus::EnCours->value);
    }

    public function hasActiveStepSubmissions(string $stepId): int
    {
        return $this->tokenRepository->countActiveByStepId($stepId, SubmissionStatus::EnCours->value);
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
