<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

final class GetTokenTest extends Base
{
    // ── getTokenWithContext ──────────────────────────────────────

    public function testGetTokenWithContextReturnsNullForInvalidToken(): void
    {
        $result = $this->workflow->getTokenWithContext('nonexistent_token');
        $this->assertNull($result);
    }

    public function testGetTokenWithContextReturnsDataForRealToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
    }

    public function testGetTokenWithContextReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getTokenWithContext('');
        $this->assertNull($result);
    }

    public function testGetTokenWithContextReturnsNullForTooLongToken(): void
    {
        $result = $this->workflow->getTokenWithContext(str_repeat('a', 256));
        $this->assertNull($result);
    }

    // ── getTokenByIdWithContext ──────────────────────────────────

    public function testGetTokenByIdWithContextReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('nonexistent_id');
        $this->assertNull($result);
    }

    public function testGetTokenByIdWithContextReturnsDataForRealId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
    }

    public function testGetTokenByIdWithContextReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('');
        $this->assertNull($result);
    }

    // ── getTokenWithContext: returns step_label and form_label ────

    public function testGetTokenWithContextReturnsStepAndFormLabels(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
    }

    // ── getTokenByIdWithContext: returns all required fields ──────

    public function testGetTokenByIdWithContextReturnsAllRequiredFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        $this->assertArrayHasKey('step_label', $result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('submitted_by', $result);
    }

    // ── getTokenWithContext: returns all expected fields ──────────

    public function testGetTokenWithContextReturnsAllExpectedFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        $expectedFields = ['token', 'step_id', 'submission_id', 'email', 'step_label', 'form_label', 'data', 'status'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }

    // ── getTokenByIdWithContext: returns all expected fields ──────

    public function testGetTokenByIdWithContextReturnsAllExpectedFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        $expectedFields = ['token', 'step_id', 'submission_id', 'email', 'step_label', 'form_label', 'data', 'status'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }
}
