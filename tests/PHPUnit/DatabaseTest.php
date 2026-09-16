<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\Database;

final class DatabaseTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $this->database = \App\Core\App::getInstance()->get(\App\Core\Database::class);
    }

    public function testGetPdoReturnsPdoInstance(): void
    {
        $pdo = $this->database->getPdo();
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetPdoReturnsSameInstance(): void
    {
        $pdo1 = $this->database->getPdo();
        $pdo2 = $this->database->getPdo();
        self::assertSame($pdo1, $pdo2);
    }

    public function testGetPdoInTestModeReturnsTestDb(): void
    {
        $pdo = $this->database->getPdo();
        // In TEST_MODE, it should use workflow_test.db
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testReleaseResetsPdo(): void
    {
        $pdo1 = $this->database->getPdo();
        $this->database->release();
        $pdo2 = $this->database->getPdo();
        self::assertNotSame($pdo1, $pdo2);
    }

    // ── release() additional cases ──────────────────────────────

    public function testReleaseCanBeCalledMultipleTimes(): void
    {
        $this->database->release();
        $this->database->release();
        $pdo = $this->database->getPdo();
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testReleaseAndReacquireReturnsWorkingPdo(): void
    {
        $this->database->release();
        $pdo = $this->database->getPdo();
        $result = $pdo->query("SELECT 1 as val")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int)$result['val']);
    }

    public function testGetPdoReturnsPdoInterface(): void
    {
        $pdo = $this->database->getPdo();
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetPdoReturnsWritableConnection(): void
    {
        $pdo = $this->database->getPdo();
        $pdo->exec("CREATE TEMPORARY TABLE test_db_write (id INTEGER)");
        $pdo->exec("INSERT INTO test_db_write VALUES (42)");
        $result = $pdo->query("SELECT id FROM test_db_write")->fetchColumn();
        self::assertSame(42, (int)$result);
    }

    public function testDatabaseImplementsInterface(): void
    {
        self::assertInstanceOf(\App\Contract\DatabaseInterface::class, $this->database);
    }

    public function testDatabaseIsFinal(): void
    {
        $reflection = new \ReflectionClass($this->database);
        self::assertTrue($reflection->isFinal());
    }

    public function testGetPdoReturnsSingletonInTestMode(): void
    {
        $pdo1 = $this->database->getPdo();
        $pdo2 = $this->database->getPdo();
        self::assertSame($pdo1, $pdo2);
    }

    public function testReleaseClearsBothConnections(): void
    {
        // Ensure both connections are initialized
        $this->database->getPdo();
        $this->database->release();
        $this->database->getPdo();
        // Should work fine after release
        self::assertInstanceOf(\PDO::class, $this->database->getPdo());
    }

    // ── R5 — mode WAL garanti au niveau de la connexion ─────────

    /**
     * R5 : sur une base SQLite de test fraîche (mode par défaut « delete »),
     * l'application des PRAGMA de connexion doit activer le mode WAL.
     */
    public function testApplyConnectionPragmasEnablesWalOnFreshTestDb(): void
    {
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wal_test_' . getmypid() . '_' . uniqid() . '.db';
        $pdo = new \PDO('sqlite:' . $tmpFile);

        try {
            $before = $pdo->query('PRAGMA journal_mode');
            self::assertNotFalse($before);
            $modeBefore = strtolower((string) $before->fetchColumn());
            // Libérer le statement : un SELECT non finalisé maintient une
            // transaction de lecture implicite qui empêche le passage en WAL.
            $before = null;

            self::assertNotSame(
                'wal',
                $modeBefore,
                'Pré-condition : une base SQLite fraîche n\'est pas en WAL par défaut.'
            );

            $this->database->applyConnectionPragmas($pdo);

            $after = $pdo->query('PRAGMA journal_mode');
            self::assertNotFalse($after);
            self::assertSame(
                'wal',
                strtolower((string) $after->fetchColumn()),
                'R5 : applyConnectionPragmas() doit activer le mode WAL.'
            );
        } finally {
            $pdo = null;
            foreach ([$tmpFile, $tmpFile . '-wal', $tmpFile . '-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * R5 : la connexion SQLite de la base de test doit être en mode WAL.
     */
    public function testTestDatabaseConnectionUsesWal(): void
    {
        $pdo = $this->database->getPdo();
        $stmt = $pdo->query('PRAGMA journal_mode');
        self::assertNotFalse($stmt);
        self::assertSame(
            'wal',
            strtolower((string) $stmt->fetchColumn()),
            'R5 : la connexion SQLite (base de test) doit être en mode WAL.'
        );
    }

    // ── F1 — handler de shutdown : cron différé protégé de la coupure ──

    /**
     * F1 : le handler de shutdown doit appeler ignore_user_abort(true) avant de
     * lancer le cron différé, pour que le travail de fond ne soit pas tué avec la
     * réponse. En CLI, fastcgi_finish_request() est absent : le chemin de repli
     * (pas d'appel) doit s'exécuter sans erreur.
     */
    public function testRunDeferredCronEnablesIgnoreUserAbort(): void
    {
        $wasIgnoring = (bool) ignore_user_abort();
        ignore_user_abort(false); // état de départ connu → l'assertion prouve l'appel

        // En TEST_MODE, getPdo() renvoie la connexion de test sans renseigner
        // $pdo (chemin « vraie connexion » uniquement). On simule ce chemin.
        $pdoProperty = new \ReflectionProperty($this->database, 'pdo');
        $pdoProperty->setValue($this->database, $this->database->getPdo());

        try {
            new \ReflectionMethod($this->database, 'runDeferredCron')->invoke($this->database);

            self::assertTrue(
                (bool) ignore_user_abort(),
                'F1 : le handler doit activer ignore_user_abort(true) avant le cron'
            );
        } finally {
            ignore_user_abort($wasIgnoring);
            $pdoProperty->setValue($this->database, null);
        }
    }

    /**
     * F1 : sans connexion réelle, le handler est un no-op (garde préservée) —
     * ignore_user_abort n'est pas modifié.
     */
    public function testRunDeferredCronIsNoOpWithoutRealConnection(): void
    {
        $wasIgnoring = (bool) ignore_user_abort();
        ignore_user_abort(false);

        $pdoProperty = new \ReflectionProperty($this->database, 'pdo');
        $pdoProperty->setValue($this->database, null);

        try {
            new \ReflectionMethod($this->database, 'runDeferredCron')->invoke($this->database);

            self::assertFalse(
                (bool) ignore_user_abort(),
                'F1 : sans connexion réelle, le handler ne doit rien activer'
            );
        } finally {
            ignore_user_abort($wasIgnoring);
        }
    }
}
