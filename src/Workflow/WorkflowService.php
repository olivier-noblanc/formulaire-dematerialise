<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Contract\WorkflowInterface;
use App\Core\Database;
use App\Forms\FieldService;
use App\Mail\MailService;
use App\Repository\SettingsRepository;
use App\Settings\SettingsService;

/**
 * Facade — wraps WorkflowEngine, single entry point for procedural API.
 */
final readonly class WorkflowService implements WorkflowInterface
{
    private WorkflowEngine $workflowEngine;

    public function __construct(Database $database)
    {
        $settingsRepository = new SettingsRepository($database);
        $settingsService = new SettingsService($settingsRepository);
        $mailService     = new MailService($database, $settingsService);
        $fieldService   = new FieldService($database);
        $conditionEvaluator = new ConditionEvaluator();
        $this->workflowEngine = new WorkflowEngine($database, $settingsService, $mailService, $fieldService, $conditionEvaluator);
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
        return $this->workflowEngine->getTokenWithContext($tokenValue);
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
        return $this->workflowEngine->getTokenByIdWithContext($tokenId);
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
        return $this->workflowEngine->getWorkflowSteps($formId);
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
     *   form_label: string
     * }|null
     */
    public function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        return $this->workflowEngine->getSubmissionWithFormLabel($submissionId);
    }

    /** @param array<string, mixed> $formData */
    public function resolveDynamicRecipient(string $recipient, array $formData, ?string $submissionId = null): string
    {
        return $this->workflowEngine->resolveDynamicRecipient($recipient, $formData, $submissionId);
    }

    public function advanceWorkflow(string $submissionId): void
    {
        $this->workflowEngine->advanceWorkflow($submissionId);
    }

    /**
     * @param string $action 'valider' or 'refuser'
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
    public function validateToken(string $token, string $action = 'valider', string $comment = '', string $doneBy = ''): array
    {
        return $this->workflowEngine->validateToken($token, $action, $comment, $doneBy);
    }

    public function hasActiveSubmissions(string $formId): int
    {
        return $this->workflowEngine->hasActiveSubmissions($formId);
    }

    public function hasActiveStepSubmissions(string $stepId): int
    {
        return $this->workflowEngine->hasActiveStepSubmissions($stepId);
    }
}
