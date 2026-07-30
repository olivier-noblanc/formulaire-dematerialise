<?php
declare(strict_types=1);

namespace App\Tests\Repository\TokenRepositoryTest;

/**
 * Tests: findWithStepsBySubmission, findDetailedWithStepsBySubmission,
 * findBySubmissionIds, existsForSubmissionAndEmail, findEmailAndStepLabelById,
 * findForExport.
 */
final class FindQueriesTest extends Base
{
    // ── findWithStepsBySubmission() ─────────────────────────────

    public function testFindWithStepsBySubmissionReturnsJoinedData(): void
    {
        [$formId, $stepId] = $this->createFormAndStep(stepLabel: 'Étape 1', ordre: 1);
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'validator@test.com');

        $result = $this->repo->findWithStepsBySubmission($subId);

        self::assertCount(1, $result);
        self::assertSame('validator@test.com', $result[0]['email']);
        self::assertSame('Étape 1', $result[0]['step_label']);
        self::assertSame(1, $result[0]['ordre']);
    }

    public function testFindWithStepsBySubmissionOrdersByStepOrdre(): void
    {
        [$formId, $step1] = $this->createFormAndStep(stepLabel: 'Étape 1', ordre: 1);
        $stepId2 = \generate_uuid();
        $this->pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Étape 2', 2, 1, '')")
            ->execute([$stepId2, $formId]);
        $this->createdIds['steps'][] = $stepId2;

        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId2, 'second@test.com');
        $this->createToken($subId, $step1, 'first@test.com');

        $result = $this->repo->findWithStepsBySubmission($subId);

        self::assertCount(2, $result);
        self::assertSame('first@test.com', $result[0]['email']);
        self::assertSame('second@test.com', $result[1]['email']);
    }

    public function testFindWithStepsBySubmissionReturnsEmptyForUnknownSubmission(): void
    {
        $result = $this->repo->findWithStepsBySubmission('nonexistent');
        self::assertSame([], $result);
    }

    // ── findDetailedWithStepsBySubmission() ─────────────────────

    public function testFindDetailedWithStepsBySubmissionIncludesAllFields(): void
    {
        [$formId, $stepId] = $this->createFormAndStep(stepLabel: 'Étape 1', ordre: 1);
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'validator@test.com', doneAtOffset: '-1 hour');

        $result = $this->repo->findDetailedWithStepsBySubmission($subId);

        self::assertCount(1, $result);
        self::assertSame($subId, $result[0]['submission_id']);
        self::assertNotNull($result[0]['done_at']);
        self::assertArrayHasKey('relance_count', $result[0]);
        self::assertArrayHasKey('expires_at', $result[0]);
    }

    public function testFindDetailedWithStepsBySubmissionOrdersBySentAtWithinSameStep(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'later@test.com', sentAtOffset: '-1 hour');
        $this->createToken($subId, $stepId, 'earlier@test.com', sentAtOffset: '-2 hours');

        $result = $this->repo->findDetailedWithStepsBySubmission($subId);

        self::assertCount(2, $result);
        self::assertSame('earlier@test.com', $result[0]['email']);
        self::assertSame('later@test.com', $result[1]['email']);
    }

    // ── findBySubmissionIds() ────────────────────────────────────

    public function testFindBySubmissionIdsGroupsResultsBySubmission(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $sub1 = $this->createSubmission($formId);
        $sub2 = $this->createSubmission($formId);
        $this->createToken($sub1, $stepId, 'a@test.com');
        $this->createToken($sub2, $stepId, 'b@test.com');

        $result = $this->repo->findBySubmissionIds([$sub1, $sub2]);

        self::assertArrayHasKey($sub1, $result);
        self::assertArrayHasKey($sub2, $result);
        self::assertCount(1, $result[$sub1]);
        self::assertSame('a@test.com', $result[$sub1][0]['email']);
    }

    public function testFindBySubmissionIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findBySubmissionIds([]));
    }

    // ── existsForSubmissionAndEmail() ────────────────────────────

    public function testExistsForSubmissionAndEmailReturnsTrueWhenPresent(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'present@test.com');

        self::assertTrue($this->repo->existsForSubmissionAndEmail($subId, 'present@test.com'));
    }

    public function testExistsForSubmissionAndEmailReturnsFalseWhenAbsent(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'present@test.com');

        self::assertFalse($this->repo->existsForSubmissionAndEmail($subId, 'absent@test.com'));
    }

    // ── findEmailAndStepLabelById() ──────────────────────────────

    public function testFindEmailAndStepLabelByIdReturnsData(): void
    {
        [$formId, $stepId] = $this->createFormAndStep(stepLabel: 'Étape validation');
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId, 'lookup@test.com');

        $result = $this->repo->findEmailAndStepLabelById($tokenId);

        self::assertNotNull($result);
        self::assertSame('lookup@test.com', $result['email']);
        self::assertSame('Étape validation', $result['step_label']);
    }

    public function testFindEmailAndStepLabelByIdReturnsNullForUnknownToken(): void
    {
        self::assertNull($this->repo->findEmailAndStepLabelById('nonexistent'));
    }

    // ── findForExport() ───────────────────────────────────────────

    public function testFindForExportReturnsTokenFields(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'export@test.com');

        $result = $this->repo->findForExport($subId);

        self::assertCount(1, $result);
        self::assertSame($stepId, $result[0]['step_id']);
        self::assertSame('export@test.com', $result[0]['email']);
        self::assertArrayHasKey('sent_at', $result[0]);
        self::assertArrayHasKey('done_at', $result[0]);
        self::assertArrayHasKey('expires_at', $result[0]);
    }

    public function testFindForExportReturnsEmptyForUnknownSubmission(): void
    {
        self::assertSame([], $this->repo->findForExport('nonexistent'));
    }
}
