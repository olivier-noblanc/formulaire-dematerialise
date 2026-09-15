<?php
declare(strict_types=1);

namespace App\Tests\Repository\TokenRepositoryTest;

/**
 * R2 (audit 2026-09-14) — reproduction de la race des relances.
 *
 * remind.php lit relance_count puis, avant ce fix, écrivait
 * `SET relance_count = <valeur lue + 1>` sans compare-and-swap. Deux
 * exécutions concurrentes (Task Scheduler 12h + lazy_cron web) lisaient le
 * même relance_count=0, envoyaient chacune un mail et écrasaient toutes les
 * deux la valeur à 1 (relance_count sous-compté, plafond relance_max
 * contourné).
 *
 * Le fix introduit tryClaimRelance() : un CAS atomique sur relance_count
 * (COALESCE(relance_count, 0) = valeur attendue) + filtre invalidated_at.
 * Le test reproduit le scénario déterministe : deux revendications avec la
 * même valeur attendue (comme deux workers ayant lu le même état) — une seule
 * remporte le créneau, la seconde repart avec 0 ligne affectée.
 *
 * Fichier : tests/PHPUnit/Repository/TokenRepositoryTest/RelanceClaimTest.php
 */
final class RelanceClaimTest extends Base
{
    /** @return array{relance_count: int|string|null, relance_at: string|null} */
    private function readRelanceState(string $tokenId): array
    {
        $stmt = $this->pdo->prepare('SELECT relance_count, relance_at FROM tokens WHERE id = ?');
        $stmt->execute([$tokenId]);
        /** @var array{relance_count: int|string|null, relance_at: string|null} $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row;
    }

    public function testTryClaimRelanceWinsAndIncrementsOnce(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId);

        $relanceAt = gmdate('Y-m-d H:i:s');

        self::assertSame(1, $this->repo->tryClaimRelance($tokenId, 0, $relanceAt));

        $state = $this->readRelanceState($tokenId);
        self::assertSame(1, (int) $state['relance_count']);
        self::assertSame($relanceAt, $state['relance_at']);
    }

    /**
     * Cœur de R2 : deux workers lisent relance_count=0 (valeur attendue
     * identique). Le premier revendique (1 ligne), le second — qui tient la
     * même valeur périmée — obtient 0 et ne doit PAS envoyer/recompter.
     */
    public function testTryClaimRelanceWithStaleCountLosesRace(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId);

        $winnerAt = gmdate('Y-m-d H:i:s', time() - 1);
        $loserAt  = gmdate('Y-m-d H:i:s');

        // Worker A — lit 0, revendique le créneau.
        self::assertSame(1, $this->repo->tryClaimRelance($tokenId, 0, $winnerAt));

        // Worker B — a lu 0 avant que A ne commite : sa revendication échoue.
        self::assertSame(0, $this->repo->tryClaimRelance($tokenId, 0, $loserAt));

        $state = $this->readRelanceState($tokenId);
        self::assertSame(1, (int) $state['relance_count'], 'la course ne doit incrémenter le compteur qu\'une seule fois');
        self::assertSame($winnerAt, $state['relance_at'], 'le perdant ne doit pas écraser relance_at');
    }

    public function testTryClaimRelanceTreatsNullCountAsZero(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId);

        // Ligne historique avec relance_count NULL (schéma sans NOT NULL).
        $this->pdo->prepare('UPDATE tokens SET relance_count = NULL WHERE id = ?')->execute([$tokenId]);

        self::assertSame(1, $this->repo->tryClaimRelance($tokenId, 0, gmdate('Y-m-d H:i:s')));
        self::assertSame(1, (int) $this->readRelanceState($tokenId)['relance_count']);
    }

    public function testTryClaimRelanceRefusesDoneToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId, doneAtOffset: '-1 hour');

        self::assertSame(0, $this->repo->tryClaimRelance($tokenId, 0, gmdate('Y-m-d H:i:s')));
        self::assertSame(0, (int) $this->readRelanceState($tokenId)['relance_count']);
    }

    public function testTryClaimRelanceRefusesInvalidatedToken(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId, invalidatedAtOffset: '-1 hour');

        self::assertSame(0, $this->repo->tryClaimRelance($tokenId, 0, gmdate('Y-m-d H:i:s')));
        self::assertSame(0, (int) $this->readRelanceState($tokenId)['relance_count']);
    }

    public function testReleaseRelanceClaimRestoresPreviousState(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId);

        self::assertSame(1, $this->repo->tryClaimRelance($tokenId, 0, gmdate('Y-m-d H:i:s')));

        // Envoi échoué : restaure count=0 et relance_at=NULL.
        self::assertSame(1, $this->repo->releaseRelanceClaim($tokenId, 1, 0, null));

        $state = $this->readRelanceState($tokenId);
        self::assertSame(0, (int) $state['relance_count']);
        self::assertNull($state['relance_at']);
    }

    public function testReleaseRelanceClaimIsNoOpWhenCountChanged(): void
    {
        [$formId, $stepId] = $this->createFormAndStep();
        $subId = $this->createSubmission($formId);
        $tokenId = $this->createToken($subId, $stepId);

        self::assertSame(1, $this->repo->tryClaimRelance($tokenId, 0, gmdate('Y-m-d H:i:s')));

        // La valeur attendue ne correspond plus → ne rien raboter.
        self::assertSame(0, $this->repo->releaseRelanceClaim($tokenId, 99, 0, null));
        self::assertSame(1, (int) $this->readRelanceState($tokenId)['relance_count']);
    }
}