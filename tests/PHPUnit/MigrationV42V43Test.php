<?php
declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class MigrationV42V43Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'v42v43_');
        self::assertNotFalse($path);
        $this->dbPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function schema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at TEXT)');
        $pdo->exec('CREATE TABLE forms (id TEXT PRIMARY KEY, actif INTEGER DEFAULT 1)');
        $pdo->exec('CREATE TABLE steps (id TEXT PRIMARY KEY, actif INTEGER DEFAULT 1)');
        $pdo->exec('CREATE TABLE alert_rules (id TEXT PRIMARY KEY, actif INTEGER DEFAULT 1)');
        $pdo->exec("CREATE TABLE submissions (id TEXT PRIMARY KEY, form_id TEXT NOT NULL, submitted_by TEXT NOT NULL, status TEXT DEFAULT 'en_cours', closed_at TEXT)");
        $pdo->exec("INSERT INTO schema_version VALUES (41, datetime('now'))");
        $pdo->exec("INSERT INTO forms VALUES ('f', 1)");
        $pdo->exec("INSERT INTO steps VALUES ('s', 1)");
        $pdo->exec("INSERT INTO alert_rules VALUES ('a', 1)");
        $pdo->exec("INSERT INTO submissions VALUES ('sub', 'f', 'agent', 'en_cours', NULL)");
    }

    public function testV42NominalIdempotentAndConstrainsFlags(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v42.php';
        $pdo = $this->pdo();
        $this->schema($pdo);

        self::assertSame(42, apply_migration_v42($pdo, 41));
        self::assertSame(42, apply_migration_v42($pdo, 42));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 42')->fetchColumn());

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO forms (id, actif) VALUES ('bad', 2)");
    }

    public function testV42RejectsNullBeforeDdl(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v42.php';
        $pdo = $this->pdo();
        $this->schema($pdo);
        $pdo->exec("INSERT INTO forms VALUES ('bad', NULL)");

        $this->expectException(\RuntimeException::class);
        try {
            apply_migration_v42($pdo, 41);
        } finally {
            self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM forms')->fetchColumn());
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'forms_new'")->fetchColumn());
        }
    }

    public function testV43NominalIdempotentAndRejectsNullStatus(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v43.php';
        $pdo = $this->pdo();
        $this->schema($pdo);

        self::assertSame(43, apply_migration_v43($pdo, 42));
        self::assertSame(43, apply_migration_v43($pdo, 43));
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 43')->fetchColumn());

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO submissions (id, form_id, submitted_by, status) VALUES ('bad', 'f', 'other', NULL)");
    }

    public function testV43RejectsInvalidStatusBeforeDdl(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v43.php';
        $pdo = $this->pdo();
        $this->schema($pdo);
        $pdo->exec("INSERT INTO submissions VALUES ('bad', 'f', 'other', NULL, NULL)");

        $this->expectException(\RuntimeException::class);
        try {
            apply_migration_v43($pdo, 42);
        } finally {
            self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'submissions_new'")->fetchColumn());
        }
    }
}
