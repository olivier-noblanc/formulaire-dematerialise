<?php
declare(strict_types=1);

namespace App\Tests\WorkflowEngineTest;

/**
 * getSubmissionWithFormLabel tests extracted from WorkflowEngineTest.
 */
class GetSubmissionTest extends Base
{
    public function testGetSubmissionWithFormLabelReturnsNullForInvalidId(): void
    {
        $result = $this->workflow->getSubmissionWithFormLabel('nonexistent_id');
        self::assertNull($result);
    }

    public function testGetSubmissionWithFormLabelReturnsDataForRealSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        self::assertNotNull($result);
        self::assertArrayHasKey('form_label', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('data', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getSubmissionWithFormLabel('');
        self::assertNull($result);
    }

    public function testGetSubmissionWithFormLabelReturnsSubmittedByField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        self::assertArrayHasKey('submitted_by', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsClosedAtField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        self::assertArrayHasKey('closed_at', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsAllRequiredFields(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);

        self::assertArrayHasKey('form_label', $result);
        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('submitted_by', $result);
        self::assertArrayHasKey('closed_at', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsFormLabel(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        self::assertArrayHasKey('form_label', $result);
        self::assertNotEmpty($result['form_label']);
    }

    public function testGetSubmissionWithFormLabelReturnsStatus(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        self::assertArrayHasKey('status', $result);
        self::assertContains($result['status'], ['en_cours', 'valide', 'refuse', 'annule']);
    }

    public function testGetSubmissionWithFormLabelReturnsDataField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            self::assertArrayHasKey('data', $result);
            self::assertIsString($result['data']);
        }
    }

    public function testGetSubmissionWithFormLabelDataIsJson(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $decoded = json_decode($result['data'], true);
            self::assertIsArray($decoded);
        }
    }

    public function testGetSubmissionWithFormLabelSubmittedByMayBeNull(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            self::assertTrue(
                $result['submitted_by'] === null || is_string($result['submitted_by']),
                'submitted_by should be null or string'
            );
        }
    }

    public function testGetSubmissionWithFormLabelClosedAtMayBeNull(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            self::assertTrue(
                $result['closed_at'] === null || is_string($result['closed_at']),
                'closed_at should be null or string'
            );
        }
    }

    public function testGetSubmissionWithFormLabelStatusIsValidEnum(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            self::assertContains($result['status'], ['en_cours', 'valide', 'refuse', 'annule']);
        }
    }
}
