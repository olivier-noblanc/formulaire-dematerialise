<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Migration v39 — rejeu manuel : colonne `manual_replay_count`.
 *
 * Vérifie sur une base SQLite fichier temporaire :
 *  - chemin nominal : retour 39, colonne `manual_replay_count INTEGER NOT NULL
 *    DEFAULT 0` ajoutée, lignes existantes préservées avec DEFAULT 0, version 39
 *    marquée ;
 *  - idempotence : un second appel ne rejoue rien et ne remarque pas la version ;
 *  - self-healing : colonne déjà présente mais version non marquée → la
 *    migration se termine et marque la version sans doublon de colonne ;
 *  - garde de version : rien à faire si `$current_version >= 39`.
 *
 * Fichier : tests/PHPUnit/MigrationV39Test.php
 */
final class MigrationV39Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'v39test_');
        self::assertNotFalse($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * Schéma pré-v39 : mail_log dans sa forme v38 (sans manual_replay_count).
     */
    private function createBaseSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (38, datetime('now'))");
        $pdo->exec("CREATE TABLE mail_log (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            body_html TEXT,
            status TEXT NOT NULL,
            error_message TEXT DEFAULT '',
            smtp_log TEXT DEFAULT '',
            attempts INTEGER NOT NULL DEFAULT 0,
            next_retry_at TEXT,
            actor TEXT DEFAULT '',
            ip TEXT DEFAULT ''
        )");
        $pdo->exec("INSERT INTO mail_log (id, created_at, recipient, subject, status)
                    VALUES ('old-sent', datetime('now'), 'a@test.local', 'Sujet 1', 'sent')");
        $pdo->exec("INSERT INTO mail_log (id, created_at, recipient, subject, status)
                    VALUES ('old-failed', datetime('now'), 'b@test.local', 'Sujet 2', 'failed')");
    }

    private function openPdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * @return array<int, array{name: string, type: string, notnull: int|string, dflt_value: string|null, pk: int|string}>
     */
    private function tableInfo(PDO $pdo): array
    {
        /** @var array<int, array{name: string, type: string, notnull: int|string, dflt_value: string|null, pk: int|string}> $rows */
        $rows = $pdo->query('PRAGMA table_info(mail_log)')->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    public function testHappyPathAddsColumnWithDefaultAndMarksVersion(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v39.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v39($pdo, 38);

        self::assertSame(39, $result);

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 39');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked, 'La version 39 doit être marquée après une migration réussie');

        $cols = $this->tableInfo($pdo);
        self::assertContains('manual_replay_count', array_column($cols, 'name'));
        $column = array_find($cols, fn($col): bool => $col['name'] === 'manual_replay_count');
        self::assertNotNull($column);
        self::assertSame('INTEGER', $column['type']);
        self::assertSame(1, (int) $column['notnull'], 'la colonne doit être NOT NULL');
        self::assertSame('0', (string) $column['dflt_value'], 'la colonne doit avoir DEFAULT 0');

        // Lignes pré-existantes préservées, nouvelle colonne au DEFAULT 0.
        $stmt = $pdo->query("SELECT manual_replay_count FROM mail_log WHERE id = 'old-failed'");
        $value = $stmt !== false ? $stmt->fetchColumn() : false;
        $stmt = null;
        self::assertSame(0, (int) $value);

        $stmt = $pdo->query('SELECT COUNT(*) FROM mail_log');
        $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(2, $count, 'les deux lignes pré-existantes doivent être préservées');
    }

    public function testSecondCallIsIdempotent(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v39.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);
        apply_migration_v39($pdo, 38);

        $second = apply_migration_v39($pdo, 39);
        self::assertSame(39, $second);

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 39');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked, 'la version 39 ne doit être marquée qu\'une fois');

        $names = array_column($this->tableInfo($pdo), 'name');
        self::assertSame(1, count(array_keys($names, 'manual_replay_count', true)), 'aucun doublon de colonne');
    }

    public function testSelfHealingWhenColumnAlreadyPresent(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v39.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);
        // Panne simulée : colonne créée mais marquage schema_version non effectué.
        $pdo->exec('ALTER TABLE mail_log ADD COLUMN manual_replay_count INTEGER NOT NULL DEFAULT 0');

        $result = apply_migration_v39($pdo, 38);

        self::assertSame(39, $result, 'la migration doit marquer la version sans rejouer l\'ADD COLUMN');
        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 39');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked);

        $names = array_column($this->tableInfo($pdo), 'name');
        self::assertSame(1, count(array_keys($names, 'manual_replay_count', true)), 'pas de doublon de colonne');
    }

    public function testDoesNotRunWhenCurrentVersionAlreadyAt39(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v39.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v39($pdo, 39);

        self::assertSame(39, $result);
        self::assertNotContains(
            'manual_replay_count',
            array_column($this->tableInfo($pdo), 'name'),
            'rien à faire si la version courante est déjà ≥ 39'
        );
    }
}