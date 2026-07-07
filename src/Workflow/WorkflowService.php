<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\Database;
use App\Settings\SettingsService;
use App\Mail\MailService;
use App\Forms\FieldService;
use App\Contract\WorkflowInterface;

/**
 * Facade — wraps WorkflowEngine, single entry point for procedural API.
 */
final class WorkflowService implements WorkflowInterface
{
    private WorkflowEngine $engine;

    public function __construct(Database $db)
    {
        $settings = new SettingsService($db);
        $mail     = new MailService($db, $settings);
        $fields   = new FieldService($db);
        $conditions = new ConditionEvaluator();
        $this->engine = new WorkflowEngine($db, $settings, $mail, $fields, $conditions);
    }

    /** @return array<string, mixed>|null */
    public function getTokenWithContext(string $tokenValue): ?array
    {
        return $this->engine->getTokenWithContext($tokenValue);
    }

    /** @return array<string, mixed>|null */
    public function getTokenByIdWithContext(string $tokenId): ?array
    {
        return $this->engine->getTokenByIdWithContext($tokenId);
    }

    /** @return array<int, array<string, mixed>> */
    public function getWorkflowSteps(string $formId): array
    {
        return $this->engine->getWorkflowSteps($formId);
    }

    /** @return array<string, mixed>|null */
    public function getSubmissionWithFormLabel(string $submissionId): ?array
    {
        return $this->engine->getSubmissionWithFormLabel($submissionId);
    }

    public function resolveDynamicRecipient(string $recipient, array $formData, ?string $submissionId = null): string
    {
        return $this->engine->resolveDynamicRecipient($recipient, $formData, $submissionId);
    }

    public function advanceWorkflow(string $submissionId): void
    {
        $this->engine->advanceWorkflow($submissionId);
    }

    /**
     * @param string $action 'valider' or 'refuser'
     * @return array{status: string, data?: array<string, mixed>}
     */
    public function validateToken(string $token, string $action = 'valider', string $comment = '', string $doneBy = ''): array
    {
        return $this->engine->validateToken($token, $action, $comment, $doneBy);
    }

    public function hasActiveSubmissions(string $formId): int
    {
        return $this->engine->hasActiveSubmissions($formId);
    }

    public function hasActiveStepSubmissions(string $stepId): int
    {
        return $this->engine->hasActiveStepSubmissions($stepId);
    }
}
