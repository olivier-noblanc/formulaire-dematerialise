<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

final class GetTokenTest extends Base
{
    // ── getTokenWithContext ──────────────────────────────────────

    public function testGetTokenWithContextReturnsNullForInvalidToken(): void
    {
        $result = $this->workflow->getTokenWithContext('nonexistent_token');
        self::assertNull($result);
    }

    public function testGetTokenWithContextReturnsDataForRealToken(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        self::assertArrayHasKey('step_label', $result);
        self::assertArrayHasKey('form_label', $result);
        self::assertArrayHasKey('email', $result);
    }

    public function testGetTokenWithContextReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getTokenWithContext('');
        self::assertNull($result);
    }

    public function testGetTokenWithContextReturnsNullForTooLongToken(): void
    {
        $result = $this->workflow->getTokenWithContext(str_repeat('a', 256));
        self::assertNull($result);
    }

    // ── getTokenByIdWithContext ──────────────────────────────────

    public function testGetTokenByIdWithContextReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('nonexistent_id');
        self::assertNull($result);
    }

    public function testGetTokenByIdWithContextReturnsDataForRealId(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        self::assertArrayHasKey('step_label', $result);
        self::assertArrayHasKey('form_label', $result);
        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('status', $result);
    }

    public function testGetTokenByIdWithContextReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getTokenByIdWithContext('');
        self::assertNull($result);
    }

    // ── getTokenWithContext: returns step_label and form_label ────

    public function testGetTokenWithContextReturnsStepAndFormLabels(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenWithContext($tokenVal);
        self::assertArrayHasKey('step_label', $result);
        self::assertArrayHasKey('form_label', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('status', $result);
    }

    // ── getTokenByIdWithContext: returns all required fields ──────

    public function testGetTokenByIdWithContextReturnsAllRequiredFields(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [$tokenId] = $this->createTestToken($subId, $stepId);

        $result = $this->workflow->getTokenByIdWithContext($tokenId);
        self::assertArrayHasKey('step_label', $result);
        self::assertArrayHasKey('form_label', $result);
        self::assertArrayHasKey('email', $result);
        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('submitted_by', $result);
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
            self::assertArrayHasKey($field, $result, "Missing field: $field");
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
            self::assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }
}
