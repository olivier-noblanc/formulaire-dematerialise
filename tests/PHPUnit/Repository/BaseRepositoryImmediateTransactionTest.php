<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use App\Core\Database;
use App\Repository\BaseRepository;
use PHPUnit\Framework\TestCase;

/**
 * BUG3 — BaseRepository::beginImmediateTransaction().
 *
 * `PDO::beginTransaction()` émet un `BEGIN` DEFERRED. En mode WAL, une
 * transaction qui lit puis écrit doit convertir son verrou de lecture en verrou
 * d'écriture ; si une autre connexion a committé entre-temps, SQLite échoue
 * immédiatement avec SQLITE_BUSY (« database is locked ») — un conflit de
 * snapshot que `busy_timeout` ne rejoue pas. `BEGIN IMMEDIATE` acquiert le
 * verrou d'écriture dès l'ouverture.
 *
 * Ces tests tournent sur une base SQLite temporaire isolée (schéma migré) pour
 * ne pas interférer avec la base partagée de la suite.
 */
final class BaseRepositoryImmediateTransactionTest extends TestCase
{
    private string $dbPath;
    private ?string $savedTestDbPath = null;
    private Database $db;
    private BaseRepository $repo;

    protected function setUp(): void
    {
        $this->savedTestDbPath = $GLOBALS['_test_db_path'] ?? null;
        $this->dbPath = tempnam(sys_get_temp_dir(), 'imm_txn_');
        self::assertNotFalse($this->dbPath);
        $GLOBALS['_test_db_path'] = $this->dbPath;
        $this->db = new Database();
        $this->repo = new class ($this->db) extends BaseRepository {};
        // Force l'ouverture → db_migrate() construit le schéma complet.
        self::assertInstanceOf(\PDO::class, $this->db->getPdo());
        self::assertSame(
            'wal',
            strtolower((string) $this->db->getPdo()->query('PRAGMA journal_mode')->fetchColumn()),
            'la contention testée n\'a de sens qu\'en mode WAL'
        );
    }

    protected function tearDown(): void
    {
        while ($this->repo->inTransaction()) {
            $this->repo->rollBack();
        }
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

    // ─ commit / rollback PDO après un BEGIN IMMEDIATE ────────

    public function testBeginImmediateTransactionCommitPersistsAndCloses(): void
    {
        $this->repo->execute('CREATE TEMPORARY TABLE t_imm_commit (id INTEGER, v TEXT)');

        $this->repo->beginImmediateTransaction();
        self::assertTrue($this->repo->inTransaction(), 'BEGIN IMMEDIATE doit ouvrir une transaction visible par PDO.');

        $this->repo->execute('INSERT INTO t_imm_commit (id, v) VALUES (?, ?)', [1, 'committed']);
        $this->repo->commit();

        self::assertFalse($this->repo->inTransaction(), 'commit() PDO doit fermer la transaction ouverte en IMMEDIATE.');
        $row = $this->repo->fetchOne('SELECT v FROM t_imm_commit WHERE id = ?', [1]);
        self::assertSame('committed', $row['v'] ?? null, 'la donnée écrite doit être persistée après commit.');
    }

    public function testBeginImmediateTransactionRollBackDiscardsAndCloses(): void
    {
        $this->repo->execute('CREATE TEMPORARY TABLE t_imm_rollback (id INTEGER, v TEXT)');

        $this->repo->beginImmediateTransaction();
        $this->repo->execute('INSERT INTO t_imm_rollback (id, v) VALUES (?, ?)', [1, 'discarded']);
        $this->repo->rollBack();

        self::assertFalse($this->repo->inTransaction(), 'rollBack() PDO doit fermer la transaction ouverte en IMMEDIATE.');
        self::assertNull(
            $this->repo->fetchOne('SELECT v FROM t_imm_rollback WHERE id = ?', [1]),
            'la donnée doit être annulée par rollBack.'
        );
    }

    // ── Contention WAL ───────────────────────────────────────

    /**
     * Avec BEGIN IMMEDIATE, le verrou d'écriture est tenu dès l'ouverture : une
     * seconde connexion ne peut pas committer en intercalaire (elle échoue en
     * busy), donc l'écriture de la première réussit au lieu d'échouer sur un
     * conflit de snapshot. C'est exactement ce que la correction apporte.
     */
    public function testBeginImmediateTransactionHoldsWriteLockUntilCommit(): void
    {
        $this->repo->execute('CREATE TABLE IF NOT EXISTS imm_lock (v TEXT)');

        $other = $this->openOtherConnection();
        try {
            $this->repo->beginImmediateTransaction();
            $this->repo->execute("INSERT INTO imm_lock (v) VALUES ('first')");

            $blocked = null;
            try {
                $other->exec("INSERT INTO imm_lock (v) VALUES ('interloper')");
            } catch (\PDOException $e) {
                $blocked = $e;
            }
            self::assertNotNull(
                $blocked,
                'BEGIN IMMEDIATE doit empêcher une écriture concurrente de committer avant le commit.'
            );
            self::assertStringContainsString('locked', strtolower($blocked->getMessage()));

            $this->repo->commit();
            self::assertFalse($this->repo->inTransaction());
        } finally {
            if ($this->repo->inTransaction()) {
                $this->repo->rollBack();
            }
            $other = null;
        }

        self::assertSame(
            'first',
            $this->repo->fetchOne("SELECT v FROM imm_lock WHERE v = 'first'")['v'] ?? null
        );
        self::assertNull(
            $this->repo->fetchOne("SELECT v FROM imm_lock WHERE v = 'interloper'"),
            'la connexion concurrente n\'a pas pu écrire pendant la transaction IMMEDIATE.'
        );
    }

    /**
     * Contre-preuve : une transaction DEFERRED qui lit puis tente d'écrire après
     * qu'une autre connexion a committé échoue avec « database is locked » — le
     * défaut que BEGIN IMMEDIATE évite.
     */
    public function testDeferredReadThenWriteFailsAfterConcurrentCommit(): void
    {
        $this->repo->execute('CREATE TABLE IF NOT EXISTS deferred_probe (v TEXT)');
        $this->repo->execute("INSERT INTO deferred_probe (v) VALUES ('seed')");

        $other = $this->openOtherConnection();
        try {
            $this->repo->beginTransaction(); // DEFERRED
            $this->repo->fetchAll('SELECT v FROM deferred_probe'); // snapshot de lecture

            $other->exec("INSERT INTO deferred_probe (v) VALUES ('concurrent')"); // commit concurrent

            $failed = null;
            try {
                $this->repo->execute("INSERT INTO deferred_probe (v) VALUES ('after')");
            } catch (\PDOException $e) {
                $failed = $e;
            }
            self::assertNotNull(
                $failed,
                'une transaction DEFERRED qui lit puis écrit après un commit concurrent doit échouer (SQLITE_BUSY).'
            );
        } finally {
            if ($this->repo->inTransaction()) {
                $this->repo->rollBack();
            }
            $other = null;
        }
        $this->repo->execute("DELETE FROM deferred_probe WHERE v = 'concurrent'");
    }

    private function openOtherConnection(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 200');

        return $pdo;
    }
}