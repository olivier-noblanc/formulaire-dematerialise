<?php
declare(strict_types=1);

namespace App\Tests;

use App\Enum\MailStatus;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Migration v38 — Outbox SMTP write-ahead durable.
 *
 * Vérifie sur une base SQLite fichier temporaire :
 *  - chemin nominal : retour 38, colonnes body_html/attempts/next_retry_at
 *    ajoutées, lignes existantes préservées avec DEFAULT, version 38 marquée ;
 *  - CHECK élargi : accepte les 6 statuts de MailStatus, rejette une valeur
 *    inconnue ;
 *  - FK cassée : RuntimeException ET version 38 NON marquée (leçon P0-7).
 *
 * Fichier : tests/PHPUnit/MigrationV38Test.php
 */
final class MigrationV38Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'v38test_');
        self::assertNotFalse($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * Schéma pré-v38 : mail_log dans sa forme v31 (4 statuts, sans body/attempts).
     */
    private function createBaseSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('INSERT INTO schema_version (version, applied_at) VALUES (37, datetime(\'now\'))');
        $pdo->exec("CREATE TABLE mail_log (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('sent', 'blocked', 'dry_run', 'error')),
            error_message TEXT DEFAULT '',
            smtp_log TEXT DEFAULT '',
            actor TEXT DEFAULT '',
            ip TEXT DEFAULT ''
        )");
        $pdo->exec("INSERT INTO mail_log (id, created_at, recipient, subject, status)
                    VALUES ('old-sent', datetime('now'), 'a@test.local', 'Sujet 1', 'sent')");
        $pdo->exec("INSERT INTO mail_log (id, created_at, recipient, subject, status, error_message)
                    VALUES ('old-error', datetime('now'), 'b@test.local', 'Sujet 2', 'error', 'SMTP down')");
    }

    private function openPdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public function testHappyPathAddsColumnsAndMarksVersion(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v38.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v38($pdo, 37);

        self::assertSame(38, $result);

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 38');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked, 'La version 38 doit être marquée après une migration réussie');

        $cols = $pdo->query('PRAGMA table_info(mail_log)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        self::assertContains('body_html', $names);
        self::assertContains('attempts', $names);
        self::assertContains('next_retry_at', $names);

        // Lignes existantes préservées, nouvelles colonnes aux DEFAULT.
        $stmt = $pdo->query("SELECT status, attempts, next_retry_at, body_html FROM mail_log WHERE id = 'old-error'");
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $stmt = null;
        self::assertIsArray($row);
        self::assertSame('error', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['next_retry_at']);
        self::assertNull($row['body_html']);

        $stmt = $pdo->query('SELECT COUNT(*) FROM mail_log');
        $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(2, $count, 'Les deux lignes pré-existantes doivent être préservées');
    }

    public function testCheckAcceptsEveryMailStatusAndRejectsUnknown(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v38.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);
        apply_migration_v38($pdo, 37);

        foreach (MailStatus::values() as $status) {
            $pdo->prepare("INSERT INTO mail_log (id, created_at, recipient, subject, status) VALUES (?, datetime('now'), 'x@test.local', 'S', ?)")
                ->execute(['v38-' . $status, $status]);
        }

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('CHECK constraint failed');
        $pdo->prepare("INSERT INTO mail_log (id, created_at, recipient, subject, status) VALUES ('v38-bad', datetime('now'), 'x@test.local', 'S', 'bad_status')")
            ->execute();
    }

    public function testFkFailureThrowsAndDoesNotMarkVersion(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v38.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        // FK cassée pré-existante (insertion avec foreign_keys OFF).
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('CREATE TABLE parent_ref (id TEXT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child_ref (parent_id TEXT REFERENCES parent_ref(id))');
        $pdo->exec("INSERT INTO child_ref (parent_id) VALUES ('orphan-inexistant')");

        $thrown = false;
        try {
            apply_migration_v38($pdo, 37);
        } catch (\RuntimeException $e) {
            $thrown = str_contains($e->getMessage(), 'FK integrity broken');
        }

        self::assertTrue($thrown, 'La migration doit lever si la validation FK détecte une violation');

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 38');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(0, $marked, 'P0-7 : schema_version 38 ne doit pas être marquée si la FK est cassée');
    }

    public function testFreshMailLogIsNotRebuiltTwice(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v38.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);
        apply_migration_v38($pdo, 37);

        // Second appel : idempotent, ne rejoue pas le rebuild.
        $second = apply_migration_v38($pdo, 38);
        self::assertSame(38, $second);

        $stmt = $pdo->query('SELECT COUNT(*) FROM mail_log');
        $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(2, $count);
    }
}