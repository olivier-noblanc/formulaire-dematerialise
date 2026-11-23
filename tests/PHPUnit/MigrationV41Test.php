<?php
declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationV41Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void { $this->dbPath = (string) tempnam(sys_get_temp_dir(), 'v41_'); }
    protected function tearDown(): void { if (is_file($this->dbPath)) { unlink($this->dbPath); } }

    private function openBase(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at TEXT)');
        $pdo->exec("INSERT INTO schema_version VALUES (40, datetime('now'))");
        $pdo->exec('CREATE TABLE forms (id TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO forms (id) VALUES ('form')");
        $pdo->exec("CREATE TABLE form_fields (id TEXT PRIMARY KEY, form_id TEXT NOT NULL, label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text', field_name TEXT NOT NULL, options TEXT, hint TEXT DEFAULT '', required INTEGER, ordre INTEGER DEFAULT 0, card_group TEXT DEFAULT 'Général', filled_by TEXT DEFAULT 'demandeur', validator_step TEXT DEFAULT '', visibility TEXT DEFAULT 'all', `condition` TEXT DEFAULT '')");
        $pdo->exec("INSERT INTO form_fields (id, form_id, label, field_name, required) VALUES ('f1','form','F','field',0)");
        return $pdo;
    }

    public function testCleanDatabaseGetsStrictConstraint(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v41.php';
        $pdo = $this->openBase();
        self::assertSame(41, apply_migration_v41($pdo, 40));
        $required = array_first(array_filter($pdo->query('PRAGMA table_info(form_fields)')->fetchAll(PDO::FETCH_ASSOC), static fn(array $row): bool => $row['name'] === 'required'));
        self::assertSame(1, (int) $required['notnull']);
        self::assertStringContainsString('CHECK (required IN (0, 1))', (string) $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'form_fields'")->fetchColumn());
        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO form_fields (id, form_id, label, field_name, required) VALUES ('bad','form','F','bad',2)");
    }

    public function testInvalidDataFailsBeforeDestructiveDdl(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v41.php';
        $pdo = $this->openBase();
        $pdo->exec("INSERT INTO form_fields (id, form_id, label, field_name, required) VALUES ('bad','form','F','bad',21)");
        try { apply_migration_v41($pdo, 40); self::fail('migration should reject invalid data'); }
        catch (\RuntimeException $e) {
            self::assertStringContainsString('v41 blocked', $e->getMessage());
            self::assertStringContainsString("id='bad'", $e->getMessage());
            self::assertStringContainsString('required=21', $e->getMessage());
            self::assertStringContainsString("typeof(required)='integer'", $e->getMessage());
            self::assertStringContainsString("field_name='bad'", $e->getMessage());
            self::assertStringContainsString("form_id='form'", $e->getMessage());
            self::assertStringContainsString("label='F'", $e->getMessage());
        }
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM form_fields')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'form_fields_new'")->fetchColumn());
    }

    public function testNullDataFailsPreflightBeforeDdl(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v41.php';
        $pdo = $this->openBase();
        $pdo->exec("INSERT INTO form_fields (id, form_id, label, field_name, required) VALUES ('null','form','F','null_field',NULL)");
        $this->expectException(\RuntimeException::class);
        try { apply_migration_v41($pdo, 40); }
        finally {
            self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM form_fields')->fetchColumn());
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'form_fields_new'")->fetchColumn());
        }
    }

    public function testSecondCallIsIdempotent(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v41.php';
        $pdo = $this->openBase();
        self::assertSame(41, apply_migration_v41($pdo, 40));
        self::assertSame(41, apply_migration_v41($pdo, 41));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 41')->fetchColumn());
    }

    public function testCopyFailureRollsBackAndPreservesOriginalTable(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v41.php';
        $pdo = $this->openBase();
        $pdo->exec("INSERT INTO form_fields (id, form_id, label, field_type, field_name, required) VALUES ('bad-type','form','F','invalid','bad',0)");
        $originalCount = (int) $pdo->query('SELECT COUNT(*) FROM form_fields')->fetchColumn();

        try {
            apply_migration_v41($pdo, 40);
            self::fail('copy must fail on the target field_type CHECK');
        } catch (\PDOException $e) {
            self::assertStringContainsString('CHECK', $e->getMessage());
        }

        self::assertSame($originalCount, (int) $pdo->query('SELECT COUNT(*) FROM form_fields')->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'form_fields_new'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 41')->fetchColumn());
    }

    public function testForeignKeysStateIsRestoredAfterMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v41.php';
        $pdo = $this->openBase();
        $pdo->exec('PRAGMA foreign_keys = ON');
        apply_migration_v41($pdo, 40);
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }
}
