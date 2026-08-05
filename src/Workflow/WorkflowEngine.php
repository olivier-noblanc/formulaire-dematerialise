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

    private TokenValidationHandler $validationHandler;

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
        $this->validationHandler = new TokenValidationHandler(
            $this->tokenRepository,
            $this->submissionRepository,
            $this->mailService,
        );
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
    private function fetchTokenByCondition(string $whereClause, mixed $params): ?array
    {
        return $this->tokenRepository->findTokenWithContextByCondition($whereClause, $params);
    }

    /**
     * @return list<array{
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

    /**
     * Resolve a dynamic recipient string (e.g. {{owner}}, {{field_name}}).
     *
     * @param array<string, mixed> $formData
     * @phpstan-ignore shipmonk.deadMethod (used by tests only)
     */
    public function resolveDynamicRecipient(string $recipient, mixed $formData, ?string $submissionId = null): string
    {
        // Cas spécial : {{owner}}
        if ($recipient === '{{owner}}') {
            if ($submissionId !== null) {
                $fid = $this->submissionRepository->findFormIdById($submissionId);
                if ($fid !== null && $fid !== '') {
                    $owners = $this->formRepository->findOwnersByFormId($fid);
                    $firstOwnerEmail = $owners[0]['email'] ?? '';
                    if ($owners !== [] && filter_var($firstOwnerEmail, FILTER_VALIDATE_EMAIL) !== false) {
                        return $firstOwnerEmail;
                    }
                    $adminEmail = $this->settingsService->get('admin_email');
                    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) !== false) {
                        return $adminEmail;
                    }
                }
            }
            return $recipient;
        }

        if (preg_match('/^\{\{([a-z][a-z0-9_]*)\}\}$/', $recipient, $m) === 1) {
            $fieldName = $m[1];
            if (isset($formData[$fieldName]) && (bool)($formData[$fieldName])) {
                $resolved = trim((string) $formData[$fieldName]);
                if (filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false) {
                    return $resolved;
                }
            }
            foreach ($formData as $key => $val) {
                if (strtolower((string) $key) === $fieldName && $val !== '' && $val !== null && $val !== '0') {
                    $resolved = trim((string) $val);
                    if (filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false) {
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
        return $this->validationHandler->validate(
            $token,
            $action,
            $comment,
            $doneBy,
            $this->advanceWorkflow(...),
            $this->getTokenWithContext(...),
        );
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
