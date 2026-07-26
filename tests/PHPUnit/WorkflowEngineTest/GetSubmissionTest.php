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
        $this->assertNull($result);
    }

    public function testGetSubmissionWithFormLabelReturnsDataForRealSubmission(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsNullForEmptyString(): void
    {
        $result = $this->workflow->getSubmissionWithFormLabel('');
        $this->assertNull($result);
    }

    public function testGetSubmissionWithFormLabelReturnsSubmittedByField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('submitted_by', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsClosedAtField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('closed_at', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsAllRequiredFields(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);

        $this->assertArrayHasKey('form_label', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('submitted_by', $result);
        $this->assertArrayHasKey('closed_at', $result);
    }

    public function testGetSubmissionWithFormLabelReturnsFormLabel(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('form_label', $result);
        $this->assertNotEmpty($result['form_label']);
    }

    public function testGetSubmissionWithFormLabelReturnsStatus(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel($subId);
        $this->assertArrayHasKey('status', $result);
        $this->assertContains($result['status'], ['en_cours', 'valide', 'refuse', 'annule']);
    }

    public function testGetSubmissionWithFormLabelReturnsDataField(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $this->assertArrayHasKey('data', $result);
            $this->assertIsString($result['data']);
        }
    }

    public function testGetSubmissionWithFormLabelDataIsJson(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $decoded = json_decode($result['data'], true);
            $this->assertIsArray($decoded);
        }
    }

    public function testGetSubmissionWithFormLabelSubmittedByMayBeNull(): void
    {
        [$formId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);

        $result = $this->workflow->getSubmissionWithFormLabel((string) $subId);
        if ($result) {
            $this->assertTrue(
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
            $this->assertTrue(
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
            $this->assertContains($result['status'], ['en_cours', 'valide', 'refuse', 'annule']);
        }
    }
}
