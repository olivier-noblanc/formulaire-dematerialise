<?php
declare(strict_types=1);

namespace App\Tests\Repository\TokenRepositoryTest;

use App\Core\Database;
use App\Enum\SubmissionStatus;
use App\Repository\SubmissionRepository;

/**
 * R3 (audit 2026-09-14) — gardes atomiques (CAS) sur le statut de la
 * soumission.
 *
 * Cas couverts :
 *   1. closeWithStatus clôture une soumission en_cours (succès) ;
 *   2. closeWithStatus refuse une soumission déjà clôturée (premier commit gagne) ;
 *   3. markDoneByTokenValue refuse si la soumission n'est plus en_cours ;
 *   4. markDoneAndInvalidatedById refuse si la soumission n'est plus en_cours ;
 *   5. tryInvalidateForDelegation refuse si la soumission n'est plus en_cours.
 *
 * Contrôles positifs/négatifs supplémentaires :
 *   - markDoneByTokenValue réussit sur une soumission en_cours ;
 *   - markDoneByTokenValue refuse un token déjà invalidé (invalidated_at IS NULL).
 *
 * Fichier : tests/PHPUnit/Repository/TokenRepositoryTest/SubStatusGuardTest.php
 */
final class SubStatusGuardTest extends Base
{
    private SubmissionRepository $submissionRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $db = \App\Core\App::getInstance()->get(Database::class);
        $this->submissionRepo = new SubmissionRepository($db);
    }

    private function readTokenValue(string $tokenId): string
    {
        $stmt = $this->pdo->prepare('SELECT token FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array{status: string, closed_at: string|null} */
    private function readSubmissionState(string $subId): array
    {
        $stmt = $this->pdo->prepare('SELECT status, closed_at FROM submissions WHERE id = ?');
        $stmt->execute([$subId]);
        /** @var array{status: string, closed_at: string|null} $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row;
    }

    public function testCloseWithStatusClosesEnCoursSubmission(): void
    {
        [$formId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $now = gmdate('Y-m-d H:i:s');

        self::assertTrue($this->submissionRepo->closeWithStatus($subId, $now, SubmissionStatus::Annule->value));

        $state = $this->readSubmissionState($subId);
        self::assertSame(SubmissionStatus::Annule->value, $state['status']);
        self::assertSame($now, $state['closed_at']);
    }

    public function testCloseWithStatusRefusesAlreadyClosedSubmission(): void
    {
        [$formId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $firstAt = gmdate('Y-m-d H:i:s', time() - 10);

        // Premier commit gagne.
        self::assertTrue($this->submissionRepo->closeWithStatus($subId, $firstAt, SubmissionStatus::Annule->value));

        // Second commit perd : le statut/closed_at du gagnant ne doit pas être écrasé.
        self::assertFalse(
            $this->submissionRepo->closeWithStatus($subId, gmdate('Y-m-d H:i:s'), SubmissionStatus::Refuse->value),
            'Une soumission déjà clôturée ne doit pas être re-clôturée.'
        );

        $state = $this->readSubmissionState($subId);
        self::assertSame(SubmissionStatus::Annule->value, $state['status']);
        self::assertSame($firstAt, $state['closed_at'], 'closed_at du gagnant doit être préservé.');
    }

    public function testMarkDoneByTokenValueRefusesWhenSubmissionNotEnCours(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, '{}', SubmissionStatus::Annule->value, '-1 hour');
        $tokenId = $this->createToken($subId, $stepId);

        self::assertSame(
            0,
            $this->repo->markDoneByTokenValue($this->readTokenValue($tokenId), gmdate('Y-m-d H:i:s')),
            'markDoneByTokenValue doit refuser un token dont la soumission n\'est plus en_cours.'
        );

        $stmt = $this->pdo->prepare('SELECT done_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        self::assertNull($stmt->fetchColumn(), 'done_at ne doit pas être écrit sur une soumission clôturée.');
    }

    public function testMarkDoneAndInvalidatedByIdRefusesWhenSubmissionNotEnCours(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, '{}', SubmissionStatus::Annule->value, '-1 hour');
        $tokenId = $this->createToken($subId, $stepId);
        $now = gmdate('Y-m-d H:i:s');

        self::assertFalse(
            $this->repo->markDoneAndInvalidatedById($tokenId, $now, $now),
            'markDoneAndInvalidatedById doit refuser une soumission non en_cours.'
        );

        $stmt = $this->pdo->prepare('SELECT done_at, invalidated_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        /** @var array{done_at: string|null, invalidated_at: string|null} $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($row['done_at']);
        self::assertNull($row['invalidated_at']);
    }

    public function testTryInvalidateForDelegationRefusesWhenSubmissionNotEnCours(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId, '{}', SubmissionStatus::Annule->value, '-1 hour');
        $tokenId = $this->createToken($subId, $stepId);

        self::assertSame(
            0,
            $this->repo->tryInvalidateForDelegation($tokenId),
            'tryInvalidateForDelegation doit refuser une soumission non en_cours.'
        );
    }

    public function testMarkDoneByTokenValueSucceedsOnEnCoursSubmission(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId);
        $now = gmdate('Y-m-d H:i:s');

        self::assertSame(1, $this->repo->markDoneByTokenValue($this->readTokenValue($tokenId), $now));

        $stmt = $this->pdo->prepare('SELECT done_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        self::assertSame($now, $stmt->fetchColumn());
    }

    public function testMarkDoneByTokenValueRefusesInvalidatedToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId, invalidatedAtOffset: '-1 hour');

        self::assertSame(
            0,
            $this->repo->markDoneByTokenValue($this->readTokenValue($tokenId), gmdate('Y-m-d H:i:s')),
            'markDoneByTokenValue doit refuser un token déjà invalidé.'
        );
    }
}