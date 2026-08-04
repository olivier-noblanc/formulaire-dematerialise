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
        // Délégué à WorkflowAdvancer (extraction H-01, 2026-08-04)
        $advancer = new WorkflowAdvancer(
            $this->settingsService,
            $this->mailService,
            $this->fieldService,
            $this->conditionEvaluator,
            new RecipientResolver($this->settingsService, $this->submissionRepository, $this->formRepository),
            $this->submissionRepository,
            $this->tokenRepository,
            $this->formRepository,
        );
        $advancer->advance($submissionId);
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

}
