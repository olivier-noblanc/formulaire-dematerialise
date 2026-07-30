<?php
declare(strict_types=1);

namespace App\Tests\Repository\TokenRepositoryTest;

/**
 * Tests: findPendingByEmail, findBlocked, countExpired.
 */
final class PendingAndBlockedTest extends Base
{
    // ── findPendingByEmail() ──────────────────────────────────────

    public function testFindPendingByEmailReturnsUnresolvedActiveToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $email = 'pending-' . uniqid() . '@test.com';
        $this->createToken($subId, $stepId, $email);

        $result = $this->repo->findPendingByEmail($email);

        self::assertCount(1, $result);
        self::assertSame($subId, $result[0]['submission_id']);
    }

    public function testFindPendingByEmailExcludesDoneTokens(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $email = 'done-' . uniqid() . '@test.com';
        $this->createToken($subId, $stepId, $email, doneAtOffset: '-1 hour');

        self::assertSame([], $this->repo->findPendingByEmail($email));
    }

    public function testFindPendingByEmailExcludesExpiredTokens(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $email = 'expired-' . uniqid() . '@test.com';
        $this->createToken($subId, $stepId, $email, expiresInOffset: '-1 day');

        self::assertSame([], $this->repo->findPendingByEmail($email));
    }

    public function testFindPendingByEmailExcludesClosedSubmissions(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'valide', closedAtOffset: '-1 hour');
        $email = 'closed-' . uniqid() . '@test.com';
        $this->createToken($subId, $stepId, $email);

        self::assertSame([], $this->repo->findPendingByEmail($email));
    }

    public function testFindPendingByEmailWithSearchMatchesFormLabel(): void
    {
        $email = 'search-' . uniqid() . '@test.com';
        [$formId, $stepId] = $this->createFormAndStep(formLabel: 'Demande de mutation spéciale');
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $this->createToken($subId, $stepId, $email);

        $result = $this->repo->findPendingByEmail($email, 'mutation spéciale');

        self::assertCount(1, $result);
        self::assertSame($subId, $result[0]['submission_id']);
    }

    public function testFindPendingByEmailWithSearchReturnsEmptyWhenNoMatch(): void
    {
        $email = 'nomatch-' . uniqid() . '@test.com';
        [$formId, $stepId] = $this->createFormAndStep(formLabel: 'Demande standard');
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $this->createToken($subId, $stepId, $email);

        self::assertSame([], $this->repo->findPendingByEmail($email, 'texte-absent-xyz'));
    }

    // ── findBlocked() ─────────────────────────────────────────────

    public function testFindBlockedReturnsTokenSentBeyondThreshold(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $tokenId = $this->createToken($subId, $stepId, sentAtOffset: '-100 hours');

        $result = $this->repo->findBlocked(48);

        $ids = array_column($result, 'id');
        self::assertContains($tokenId, $ids);
    }

    public function testFindBlockedExcludesRecentToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $tokenId = $this->createToken($subId, $stepId, sentAtOffset: '-1 hour');

        $result = $this->repo->findBlocked(48);

        $ids = array_column($result, 'id');
        self::assertNotContains($tokenId, $ids);
    }

    public function testFindBlockedExcludesDoneTokens(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $tokenId = $this->createToken($subId, $stepId, doneAtOffset: '-1 hour', sentAtOffset: '-100 hours');

        $result = $this->repo->findBlocked(48);

        $ids = array_column($result, 'id');
        self::assertNotContains($tokenId, $ids);
    }

    // ── countExpired() ────────────────────────────────────────────

    public function testCountExpiredCountsUnresolvedExpiredToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $this->createToken($subId, $stepId, expiresInOffset: '-1 day');

        self::assertGreaterThanOrEqual(1, $this->repo->countExpired());
    }

    public function testCountExpiredExcludesDoneTokenPastExpiry(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $before = $this->repo->countExpired();
        $this->createToken($subId, $stepId, doneAtOffset: '-1 hour', expiresInOffset: '-1 day');

        self::assertSame($before, $this->repo->countExpired());
    }

    public function testCountExpiredExcludesNonExpiredToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, status: 'en_cours');
        $before = $this->repo->countExpired();
        $this->createToken($subId, $stepId, expiresInOffset: '+7 days');

        self::assertSame($before, $this->repo->countExpired());
    }
}
