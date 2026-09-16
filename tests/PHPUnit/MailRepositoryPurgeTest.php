<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Enum\MailStatus;
use App\Repository\MailRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rétention RGPD du corps HTML — MailRepository::purgeOutboxBodies().
 *
 * Tourne sur une base SQLite temporaire isolée (schéma complet migré) pour
 * garantir un décompte exact et ne purger aucune ligne des autres tests :
 *  - statuts de succès (`sent`, `dry_run`) : corps purgé après 7 jours ;
 *  - statuts terminaux (`error`, `failed`, `blocked`) : corps purgé après 30 jours ;
 *  - `pending` : jamais purgé, même très ancien ;
 *  - les lignes sont CONSERVÉES (seul le corps passe à NULL) ;
 *  - second appel idempotent (retour 0).
 *
 * Fichier : tests/PHPUnit/MailRepositoryPurgeTest.php
 */
final class MailRepositoryPurgeTest extends TestCase
{
    private const int DAY = 86400;

    private string $dbPath;
    private ?string $savedTestDbPath = null;
    private Database $db;
    private MailRepository $repo;

    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->savedTestDbPath = $GLOBALS['_test_db_path'] ?? null;
        $this->dbPath = tempnam(sys_get_temp_dir(), 'purgetest_');
        self::assertNotFalse($this->dbPath);
        // Redirige la connexion de test vers une base fichier isolée.
        $GLOBALS['_test_db_path'] = $this->dbPath;
        $this->db = new Database();
        $this->repo = new MailRepository($this->db);
        // Force l'ouverture → db_migrate() construit le schéma complet (v39 inclus).
        self::assertInstanceOf(\PDO::class, $this->db->getPdo());
        $this->ids = [];
    }

    protected function tearDown(): void
    {
        $this->db->release();
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if ($this->savedTestDbPath === null) {
            unset($GLOBALS['_test_db_path']);
        } else {
            $GLOBALS['_test_db_path'] = $this->savedTestDbPath;
        }
    }

    private function seed(string $status, int $ageDays): string
    {
        $id = 'purge-' . bin2hex(random_bytes(8));
        $this->ids[] = $id;
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, manual_replay_count, actor, ip)
             VALUES (?, ?, 'dest@test.local', 'Sujet purge', '<p>Corps purge</p>', ?, '', '', 1, NULL, 0, 'testeur', '127.0.0.1')"
        )->execute([$id, gmdate('Y-m-d H:i:s', time() - $ageDays * self::DAY), $status]);
        return $id;
    }

    private function bodyOf(string $id): string|null|false
    {
        $stmt = $this->db->getPdo()->prepare('SELECT body_html FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn();
    }

    private function rowExists(string $id): bool
    {
        $stmt = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function successCutoff(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 7 * self::DAY);
    }

    private function terminalCutoff(): string
    {
        return gmdate('Y-m-d H:i:s', time() - 30 * self::DAY);
    }

    public function testPurgeMatrixPurgesOldBodiesAndRetainsRows(): void
    {
        $sentOld      = $this->seed(MailStatus::Sent->value, 8);
        $dryOld       = $this->seed(MailStatus::DryRun->value, 8);
        $sentFresh    = $this->seed(MailStatus::Sent->value, 1);
        $errorOld     = $this->seed(MailStatus::Error->value, 31);
        $failedOld    = $this->seed(MailStatus::Failed->value, 31);
        $blockedOld   = $this->seed(MailStatus::Blocked->value, 31);
        $errorFresh   = $this->seed(MailStatus::Error->value, 10);
        $failedFresh  = $this->seed(MailStatus::Failed->value, 10);
        $pendingOld   = $this->seed(MailStatus::Pending->value, 40);
        $pendingFresh = $this->seed(MailStatus::Pending->value, 1);

        $purged = $this->repo->purgeOutboxBodies($this->successCutoff(), $this->terminalCutoff());

        self::assertSame(5, $purged, '2 succès anciens + 3 échecs terminaux anciens');

        foreach ([$sentOld, $dryOld, $errorOld, $failedOld, $blockedOld] as $id) {
            self::assertNull($this->bodyOf($id), 'le corps doit être purgé');
            self::assertTrue($this->rowExists($id), 'la ligne doit être conservée');
        }

        foreach ([$sentFresh, $errorFresh, $failedFresh, $pendingOld, $pendingFresh] as $id) {
            self::assertSame('<p>Corps purge</p>', $this->bodyOf($id), 'le corps doit être conservé');
            self::assertTrue($this->rowExists($id), 'la ligne doit être conservée');
        }
    }

    public function testPendingIsNeverPurgedEvenVeryOld(): void
    {
        $id = $this->seed(MailStatus::Pending->value, 365);

        self::assertSame(0, $this->repo->purgeOutboxBodies($this->successCutoff(), $this->terminalCutoff()));
        self::assertSame('<p>Corps purge</p>', $this->bodyOf($id));
    }

    public function testSecondPurgeIsIdempotent(): void
    {
        $this->seed(MailStatus::Sent->value, 8);
        $this->seed(MailStatus::Failed->value, 40);

        self::assertSame(2, $this->repo->purgeOutboxBodies($this->successCutoff(), $this->terminalCutoff()));
        self::assertSame(0, $this->repo->purgeOutboxBodies($this->successCutoff(), $this->terminalCutoff()), 'rien à purger une seconde fois');
    }
}