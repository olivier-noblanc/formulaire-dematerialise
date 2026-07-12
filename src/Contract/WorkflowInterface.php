<?php

declare(strict_types=1);

namespace App\Contract;

interface WorkflowInterface
{
    /** @return array<string, mixed>|null */
    public function getTokenWithContext(string $tokenValue): ?array;
    /** @return array<string, mixed>|null */
    public function getTokenByIdWithContext(string $tokenId): ?array;
    /** @return array<int, array<string, mixed>> */
    public function getWorkflowSteps(string $formId): array;
    /** @return array<string, mixed>|null */
    public function getSubmissionWithFormLabel(string $submissionId): ?array;
    public function resolveDynamicRecipient(string $recipient, array $formData, ?string $submissionId = null): string;
    public function advanceWorkflow(string $submissionId): void;
    /** @return array{status: string, data?: array<string, mixed>} */
    public function validateToken(string $token, string $action = 'valider', string $comment = '', string $doneBy = ''): array;
    public function hasActiveSubmissions(string $formId): int;
    public function hasActiveStepSubmissions(string $stepId): int;
}
