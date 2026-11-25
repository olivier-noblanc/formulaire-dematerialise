<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ── Durcissement SQLite — PRAGMA de session ─────────────────
    //
    // Les trois PRAGMA de sécurité sont posés au point central
    // (Database::applySessionPragmas) pour toute connexion applicative :
    //   - foreign_keys = ON : SQLite les désactive par défaut → sans ça les
    //     ON DELETE CASCADE / CHECK de FK ne s'appliquent jamais ;
    //   - busy_timeout = Database::BUSY_TIMEOUT_MS : évite les SQLITE_BUSY
    //     immédiats en accès concurrent (cron + requête web) ;
    //   - trusted_schema = OFF : empêche le schéma (vue, trigger, CHECK,
    //     colonne générée) d'invoquer des fonctions non « innocuous ».
    //     Disponible depuis SQLite 3.31.0 seulement → détection explicite.

    /**
     * Une connexion fraîche n'applique PAS les FK par défaut : la garantie
     * n'existe que si applySessionPragmas() les active.
     */
    public function testApplySessionPragmasEnablesForeignKeysOnFreshConnection(): void
    {
        $tmpFile = $this->tempDbPath();
        $pdo = new \PDO('sqlite:' . $tmpFile);

        try {
            self::assertSame(
                0,
                $this->pragmaInt($pdo, 'foreign_keys'),
                'Pré-condition : SQLite désactive foreign_keys par défaut.'
            );

            \App\Core\Database::applySessionPragmas($pdo);

            self::assertSame(
                1,
                $this->pragmaInt($pdo, 'foreign_keys'),
                'applySessionPragmas() doit activer foreign_keys sur la connexion.'
            );
        } finally {
            $this->dropTempDb($pdo, $tmpFile);
        }
    }

    /**
     * Le busy_timeout doit valoir la constante du projet (valeur unique,
     * pas une valeur recopiée dans chaque appelant).
     */
    public function testApplySessionPragmasSetsProjectBusyTimeout(): void
    {
        $tmpFile = $this->tempDbPath();
        $pdo = new \PDO('sqlite:' . $tmpFile);

        try {
            \App\Core\Database::applySessionPragmas($pdo);

            self::assertSame(
                \App\Core\Database::BUSY_TIMEOUT_MS,
                $this->pragmaInt($pdo, 'busy_timeout'),
                'applySessionPragmas() doit poser le busy_timeout du projet.'
            );
        } finally {
            $this->dropTempDb($pdo, $tmpFile);
        }
    }

    /**
     * trusted_schema=OFF uniquement si le SQLite embarqué le supporte
     * (≥ 3.31.0). Sur une version plus ancienne, le PRAGMA est inconnu de
     * SQLite : il ne peut être ni posé ni vérifié — le test documente alors
     * explicitement la limitation au lieu de faire semblant.
     */
    public function testApplySessionPragmasDisablesTrustedSchemaOnSupportedRuntime(): void
    {
        $tmpFile = $this->tempDbPath();
        $pdo = new \PDO('sqlite:' . $tmpFile);

        try {
            $version = $this->sqliteVersion($pdo);

            if (!\App\Core\Database::sqliteSupportsTrustedSchema($version)) {
                self::markTestSkipped(
                    'SQLite ' . $version . ' < ' . \App\Core\Database::TRUSTED_SCHEMA_MIN_SQLITE
                    . ' : PRAGMA trusted_schema non supporté (limitation documentée, '
                    . 'non contournable côté application).'
                );
            }

            self::assertSame(
                1,
                $this->pragmaInt($pdo, 'trusted_schema'),
                'Pré-condition : trusted_schema est activé par défaut.'
            );

            \App\Core\Database::applySessionPragmas($pdo);

            self::assertSame(
                0,
                $this->pragmaInt($pdo, 'trusted_schema'),
                'applySessionPragmas() doit désactiver trusted_schema quand le runtime le supporte.'
            );
        } finally {
            $this->dropTempDb($pdo, $tmpFile);
        }
    }

    /**
     * Détection de compatibilité testable sans SQLite ancien : la matrice de
     * versions prouve le seuil 3.31.0 (première version exposant trusted_schema).
     */
    #[DataProvider('trustedSchemaVersionProvider')]
    public function testSqliteSupportsTrustedSchemaDetection(string $version, bool $expected): void
    {
        self::assertSame($expected, \App\Core\Database::sqliteSupportsTrustedSchema($version));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function trustedSchemaVersionProvider(): array
    {
        return [
            'antérieure au pragma' => ['3.30.1', false],
            'seuil exact'          => ['3.31.0', true],
            'courante du runtime'  => ['3.53.2', true],
        ];
    }

    /**
     * Les PRAGMA de session ne doivent pas modifier le mode journal : le même
     * durcissement est appliqué aux fichiers de sauvegarde ouverts en lecture
     * (VACUUM INTO, contrôle de base uploadée) et un passage en WAL y laisserait
     * une trace persistante indésirable dans le fichier de sauvegarde.
     */
    public function testApplySessionPragmasDoesNotChangeJournalMode(): void
    {
        $tmpFile = $this->tempDbPath();
        $pdo = new \PDO('sqlite:' . $tmpFile);

        try {
            $before = $this->pragmaString($pdo, 'journal_mode');
            \App\Core\Database::applySessionPragmas($pdo);
            $after = $this->pragmaString($pdo, 'journal_mode');

            self::assertSame($before, $after, 'Le durcissement de session ne doit pas changer journal_mode.');
            self::assertNotSame('wal', $after, 'Un fichier sauvegardé ne doit pas être converti en WAL.');
        } finally {
            $this->dropTempDb($pdo, $tmpFile);
        }
    }

    /**
     * La connexion applicative réelle (post-migration) doit porter les trois
     * PRAGMA : c'est le point de passage de toute la couche Repository.
     */
    public function testApplicationConnectionIsHardenedAfterMigration(): void
    {
        $pdo = $this->database->getPdo();
        $version = $this->sqliteVersion($pdo);

        self::assertSame(1, $this->pragmaInt($pdo, 'foreign_keys'), 'La connexion applicative doit avoir foreign_keys=ON.');
        self::assertSame(
            \App\Core\Database::BUSY_TIMEOUT_MS,
            $this->pragmaInt($pdo, 'busy_timeout'),
            'La connexion applicative doit porter le busy_timeout du projet.'
        );

        if (!\App\Core\Database::sqliteSupportsTrustedSchema($version)) {
            self::markTestSkipped(
                'SQLite ' . $version . ' : PRAGMA trusted_schema non supporté (limitation documentée).'
            );
        }

        self::assertSame(0, $this->pragmaInt($pdo, 'trusted_schema'), 'La connexion applicative doit avoir trusted_schema=OFF.');
    }

    // ── Durcissement comportemental : schéma non fiable ─────────
    //
    // preuve que trusted_schema=OFF protège réellement : une vue définie dans
    // un fichier dont le schéma est contrôlé par un tiers peut appeler une
    // fonction applicative non « innocuous ». Avec trusted_schema=ON (défaut
    // SQLite), la vue s'exécute ; avec OFF, SQLite refuse avant exécution.
    public function testApplySessionPragmasBlocksFunctionCalledFromSchema(): void
    {
        $tmpFile = $this->tempDbPath();
        // PHP 8.5 : la fonction de test doit être enregistrée sur le driver
        // Pdo\Sqlite (createFunction(), non déprécié — contrairement à
        // PDO::sqliteCreateFunction()). Passer par `Pdo\Sqlite::connect()`
        // (et non `PDO::connect()`, dont le type de retour `static` reste PDO)
        // donne à PHPStan le type réellement manipulé.
        $pdo = \Pdo\Sqlite::connect('sqlite:' . $tmpFile);

        try {
            if (!\App\Core\Database::sqliteSupportsTrustedSchema($this->sqliteVersion($pdo))) {
                self::markTestSkipped('PRAGMA trusted_schema non supporté par ce runtime SQLite.');
            }

            $pdo->createFunction('fn_hardening_probe', static fn(int $x): int => $x * 2);
            $pdo->exec('CREATE TABLE t(x INTEGER)');
            $pdo->exec('CREATE VIEW v AS SELECT fn_hardening_probe(x) AS y FROM t');
            $pdo->exec('INSERT INTO t VALUES (21)');

            $stmt = $pdo->query('SELECT y FROM v');
            self::assertNotFalse($stmt);
            self::assertSame(42, (int) $stmt->fetchColumn(), 'Sans durcissement, la vue appelle la fonction.');
            $stmt = null;

            \App\Core\Database::applySessionPragmas($pdo);

            $this->expectException(\PDOException::class);
            $pdo->query('SELECT y FROM v');
        } finally {
            $this->dropTempDb($pdo, $tmpFile);
        }
    }

    private function tempDbPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pragma_' . getmypid() . '_' . uniqid('', true) . '.db';
    }

    private function pragmaInt(\PDO $pdo, string $pragma): int
    {
        $stmt = $pdo->query('PRAGMA ' . $pragma);
        self::assertNotFalse($stmt);
        $value = $stmt->fetchColumn();
        // Libérer le statement : il tiendrait sinon un verrou de lecture.
        $stmt = null;

        return (int) $value;
    }

    private function pragmaString(\PDO $pdo, string $pragma): string
    {
        $stmt = $pdo->query('PRAGMA ' . $pragma);
        self::assertNotFalse($stmt);
        $value = (string) $stmt->fetchColumn();
        $stmt = null;

        return strtolower($value);
    }

    private function sqliteVersion(\PDO $pdo): string
    {
        $stmt = $pdo->query('SELECT sqlite_version()');
        self::assertNotFalse($stmt);
        $version = (string) $stmt->fetchColumn();
        $stmt = null;

        return $version;
    }

    private function dropTempDb(?\PDO &$pdo, string $tmpFile): void
    {
        $pdo = null;
        foreach ([$tmpFile, $tmpFile . '-wal', $tmpFile . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
