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

    /** @return array<string, mixed>|null */
    public function getTokenWithContext(string $tokenValue): ?array
    {
        return $this->workflowEngine->getTokenWithContext($tokenValue);
    }

    /** @return array<string, mixed>|null */
    public function getTokenByIdWithContext(string $tokenId): ?array
    {
        return $this->workflowEngine->getTokenByIdWithContext($tokenId);
    }

    /** @return array<int, array<string, mixed>> */
    public function getWorkflowSteps(string $formId): array
    {
        return $this->workflowEngine->getWorkflowSteps($formId);
    }

    /** @return array<string, mixed>|null */
    public function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        return $this->workflowEngine->getSubmissionWithFormLabel($submissionId);
    }

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
     * @return array{status: string, data?: array<string, mixed>}
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
