<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Enum\MailStatus;
use App\Repository\MailRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu manuel opérateur — MailRepository::claimFailedForManualReplay().
 *
 * Vérifie le CAS de revendication d'une ligne `failed` :
 *  - succès : la ligne repasse en `error`, `attempts` réinitialisé à 0, bail
 *    `next_retry_at` posé, `manual_replay_count` incrémenté ;
 *  - concurrence : un second clic est refusé (statut déjà passé à `error`) ;
 *  - refus : statut ≠ `failed` ;
 *  - refus : corps purgé (`body_html IS NULL`) ;
 *  - refus : plafond `manual_replay_count >= $maxManual` ;
 *  - refus : ligne inexistante.
 *
 * Fichier : tests/PHPUnit/MailRepositoryManualReplayTest.php
 */
final class MailRepositoryManualReplayTest extends TestCase
{
    private const int MAX_MANUAL = 3;

    private Database $db;
    private MailRepository $repo;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->repo = new MailRepository($this->db);
        $this->createdIds = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM mail_log WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    private function newId(): string
    {
        $id = 'manual-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        return $id;
    }

    /**
     * @param array{status?: string, body_html?: string|null, manual_replay_count?: int, next_retry_at?: string|null, attempts?: int, recipient?: string} $overrides
     */
    private function seed(array $overrides = []): string
    {
        $id = $this->newId();
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, manual_replay_count, actor, ip)
             VALUES (?, datetime('now'), ?, 'Sujet manuel', ?, ?, 'SMTP down', '', ?, ?, ?, 'testeur', '127.0.0.1')"
        )->execute([
            $id,
            $overrides['recipient'] ?? 'dest@test.local',
            array_key_exists('body_html', $overrides) ? $overrides['body_html'] : '<p>Corps manuel</p>',
            $overrides['status'] ?? MailStatus::Failed->value,
            $overrides['attempts'] ?? 5,
            $overrides['next_retry_at'] ?? null,
            $overrides['manual_replay_count'] ?? 0,
        ]);
        return $id;
    }

    /**
     * @return array{status: string, body_html: string|null, attempts: int|string, next_retry_at: string|null, manual_replay_count: int|string}
     */
    private function readRow(string $id): array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT status, body_html, attempts, next_retry_at, manual_replay_count FROM mail_log WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'la ligne mail_log doit exister');
        /** @var array{status: string, body_html: string|null, attempts: int|string, next_retry_at: string|null, manual_replay_count: int|string} $row */
        return $row;
    }

    private function futureLease(int $offset = 900): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public function testClaimSucceedsAndRequeuesRowAsError(): void
    {
        $id = $this->seed();
        $lease = $this->futureLease();

        $claimed = $this->repo->claimFailedForManualReplay($id, $lease, self::MAX_MANUAL);

        self::assertIsArray($claimed);
        self::assertSame($id, $claimed['id']);
        self::assertSame('dest@test.local', $claimed['recipient']);
        self::assertSame('<p>Corps manuel</p>', $claimed['body_html']);
        self::assertSame(1, $claimed['manual_replay_count']);

        $row = $this->readRow($id);
        self::assertSame(MailStatus::Error->value, $row['status'], 'la ligne repasse dans la file réessayable');
        self::assertSame(0, (int) $row['attempts'], 'les tentatives automatiques sont réinitialisées');
        self::assertSame($lease, $row['next_retry_at'], 'le bail est posé sur next_retry_at');
        self::assertSame(1, (int) $row['manual_replay_count']);
        self::assertSame('<p>Corps manuel</p>', $row['body_html']);
    }

    /**
     * Cœur de la concurrence : deux clics simultanés sur la même ligne. Le
     * premier gagne et bascule le statut à `error` ; le second, qui exige
     * `status = failed`, est refusé et n'incrémente pas le compteur.
     */
    public function testSecondClaimLosesRaceAfterFirstWins(): void
    {
        $id = $this->seed();

        $first = $this->repo->claimFailedForManualReplay($id, $this->futureLease(900), self::MAX_MANUAL);
        self::assertIsArray($first, 'le premier clic gagne la revendication');

        $second = $this->repo->claimFailedForManualReplay($id, $this->futureLease(1800), self::MAX_MANUAL);
        self::assertNull($second, 'le second clic concurrent est refusé');

        $row = $this->readRow($id);
        self::assertSame(1, (int) $row['manual_replay_count'], 'le compteur n\'est incrémenté qu\'une fois');
        self::assertSame(MailStatus::Error->value, $row['status']);
    }

    public function testClaimRefusedWhenStatusIsNotFailed(): void
    {
        $others = [
            MailStatus::Pending,
            MailStatus::Sent,
            MailStatus::Error,
            MailStatus::Blocked,
            MailStatus::DryRun,
        ];
        foreach ($others as $status) {
            $id = $this->seed(['status' => $status->value]);
            self::assertNull(
                $this->repo->claimFailedForManualReplay($id, $this->futureLease(), self::MAX_MANUAL),
                "statut {$status->value} : revendication refusée"
            );
            $row = $this->readRow($id);
            self::assertSame($status->value, $row['status'], 'le statut ne doit pas changer');
            self::assertSame(0, (int) $row['manual_replay_count'], 'le compteur ne doit pas bouger');
        }
    }

    public function testClaimRefusedWhenBodyPurged(): void
    {
        $id = $this->seed(['body_html' => null]);

        self::assertNull($this->repo->claimFailedForManualReplay($id, $this->futureLease(), self::MAX_MANUAL));

        $row = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $row['status'], 'une ligne purgée reste failed');
        self::assertSame(0, (int) $row['manual_replay_count']);
        self::assertNull($row['body_html']);
    }

    public function testClaimRefusedAtManualLimit(): void
    {
        $id = $this->seed(['manual_replay_count' => self::MAX_MANUAL]);

        self::assertNull($this->repo->claimFailedForManualReplay($id, $this->futureLease(), self::MAX_MANUAL));

        $row = $this->readRow($id);
        self::assertSame(self::MAX_MANUAL, (int) $row['manual_replay_count']);
        self::assertSame(MailStatus::Failed->value, $row['status']);
    }

    public function testClaimAllowedJustBelowManualLimit(): void
    {
        $id = $this->seed(['manual_replay_count' => self::MAX_MANUAL - 1]);

        $claimed = $this->repo->claimFailedForManualReplay($id, $this->futureLease(), self::MAX_MANUAL);

        self::assertIsArray($claimed);
        self::assertSame(self::MAX_MANUAL, $claimed['manual_replay_count']);
        self::assertSame(self::MAX_MANUAL, (int) $this->readRow($id)['manual_replay_count']);
    }

    public function testClaimRefusedWhenRowMissing(): void
    {
        self::assertNull(
            $this->repo->claimFailedForManualReplay('manual-inexistant-' . bin2hex(random_bytes(6)), $this->futureLease(), self::MAX_MANUAL)
        );
    }
}