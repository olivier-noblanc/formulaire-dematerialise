<?php
declare(strict_types=1);

namespace App\Tests\Repository\TokenRepositoryTest;

/**
 * Tests: findStepsBySubmissionIds, deleteBySubmissionIds, countPurgeableByCutoff,
 * countPendingBySubmissionIds.
 */
final class AggregatesTest extends Base
{
    // ── findStepsBySubmissionIds() ───────────────────────────────

    public function testFindStepsBySubmissionIdsIncludesDoneMarker(): void
    {
        [$formId, $stepId] = $this->createFormAndStep(stepLabel: 'Étape 1', ordre: 1);
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, doneAtOffset: '-1 hour');

        $result = $this->repo->findStepsBySubmissionIds([$subId]);

        self::assertArrayHasKey($subId, $result);
        self::assertCount(1, $result[$subId]);
        self::assertSame('Étape 1', $result[$subId][0]['label']);
        self::assertNotNull($result[$subId][0]['dones']);
    }

    public function testFindStepsBySubmissionIdsHasNullDonesWithoutToken(): void
    {
        [$formId] = $this->createFormAndStep(stepLabel: 'Étape sans token', ordre: 1);
        $subId = $this->createSubmission($formId);

        $result = $this->repo->findStepsBySubmissionIds([$subId]);

        self::assertArrayHasKey($subId, $result);
        self::assertNull($result[$subId][0]['dones']);
    }

    public function testFindStepsBySubmissionIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findStepsBySubmissionIds([]));
    }

    // ── deleteBySubmissionIds() ──────────────────────────────────

    public function testDeleteBySubmissionIdsRemovesTokensAndReturnsCount(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, 'a@test.com');
        $this->createToken($subId, $stepId, 'b@test.com');

        $deleted = $this->repo->deleteBySubmissionIds([$subId]);

        self::assertSame(2, $deleted);
        self::assertSame([], $this->repo->findForExport($subId));
    }

    public function testDeleteBySubmissionIdsReturnsZeroForEmptyInput(): void
    {
        self::assertSame(0, $this->repo->deleteBySubmissionIds([]));
    }

    // ── countPurgeableByCutoff() ──────────────────────────────────

    public function testCountPurgeableByCutoffCountsOldClosedSubmission(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'valide', closedAtOffset: '-100 days');
        $this->createToken($subId, $stepId);

        $count = $this->repo->countPurgeableByCutoff(gmdate('Y-m-d H:i:s', strtotime('-30 days')));

        self::assertGreaterThanOrEqual(1, $count);
    }

    public function testCountPurgeableByCutoffExcludesRecentlyClosedSubmission(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'valide', closedAtOffset: '-1 day');
        $this->createToken($subId, $stepId);

        $count = $this->repo->countPurgeableByCutoff(gmdate('Y-m-d H:i:s', strtotime('-30 days')));

        self::assertSame(0, $count);
    }

    public function testCountPurgeableByCutoffExcludesOpenSubmission(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $this->createToken($subId, $stepId);

        $count = $this->repo->countPurgeableByCutoff(gmdate('Y-m-d H:i:s', strtotime('+1 day')));

        self::assertSame(0, $count);
    }

    // ── countPendingBySubmissionIds() ────────────────────────────

    public function testCountPendingBySubmissionIdsCountsOnlyUnresolvedTokens(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $sub1 = $this->createSubmission($formId);
        $sub2 = $this->createSubmission($formId);
        $this->createToken($sub1, $stepId, 'a@test.com');
        $this->createToken($sub1, $stepId, 'b@test.com');
        $this->createToken($sub2, $stepId, 'c@test.com');
        $this->createToken($sub2, $stepId, 'd@test.com', doneAtOffset: '-1 hour');

        $result = $this->repo->countPendingBySubmissionIds([$sub1, $sub2]);

        self::assertSame(2, $result[$sub1]);
        self::assertSame(1, $result[$sub2]);
    }

    public function testCountPendingBySubmissionIdsOmitsSubmissionWithoutPendingTokens(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $this->createToken($subId, $stepId, doneAtOffset: '-1 hour');

        $result = $this->repo->countPendingBySubmissionIds([$subId]);

        self::assertArrayNotHasKey($subId, $result);
    }

    public function testCountPendingBySubmissionIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->countPendingBySubmissionIds([]));
    }
}
