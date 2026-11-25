<?php
declare(strict_types=1);

namespace App\Tests;

use App\Enum\SubmissionStatus;
use PDO;
use PHPUnit\Framework\TestCase;

/** Baseline v42 plus strict v43/v44 migrations. */
final class MigrationV42V43Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baseline-');
        self::assertNotFalse($path);
        $this->dbPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) unlink($this->dbPath);
    }

    private function migrate(): PDO
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        db_migrate($pdo);
        return $pdo;
    }

    public function testFreshDatabaseReachesV43AndIsIdempotent(): void
    {
        $pdo = $this->migrate();
        db_migrate($pdo);

        self::assertSame(46, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='forms'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mail_log'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_submissions_active_per_form_user'")->fetchColumn());
    }

    public function testBaselineEnforcesFlagsAndFieldDomains(): void
    {
        $pdo = $this->migrate();
        $formsSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='forms'")->fetchColumn();
        $fieldsSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='form_fields'")->fetchColumn();
        self::assertStringContainsString('actif INTEGER NOT NULL', $formsSql);
        self::assertStringContainsString('required INTEGER NOT NULL', $fieldsSql);
        self::assertStringContainsString("filled_by TEXT NOT NULL DEFAULT 'demandeur'", $fieldsSql);
        self::assertStringContainsString("visibility TEXT NOT NULL DEFAULT 'all'", $fieldsSql);

        $formId = (string) $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding'")->fetchColumn();
        $this->expectException(\PDOException::class);
        $pdo->prepare('UPDATE forms SET actif = 2 WHERE id = ?')->execute([$formId]);
    }

    public function testV44RejectsInvalidExistingFieldValuesBeforeDdl(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at TEXT); INSERT INTO schema_version VALUES (43, datetime('now')); CREATE TABLE form_fields (id TEXT PRIMARY KEY, form_id TEXT NOT NULL, label TEXT NOT NULL, field_type TEXT NOT NULL, field_name TEXT NOT NULL, options TEXT, hint TEXT, required INTEGER, ordre INTEGER, card_group TEXT, filled_by TEXT, validator_step TEXT, visibility TEXT, condition TEXT); INSERT INTO form_fields VALUES ('bad','f','x','text','x',NULL,'',0,0,'Général',NULL,NULL,'garbage','')");
        $this->expectException(\RuntimeException::class);
        apply_migration_v44($pdo, 43);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 44')->fetchColumn());
    }

    public function testV43StatusIsStrictAndAllowsSeveralActiveSubmissions(): void
    {
        $pdo = $this->migrate();
        $formId = (string) $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding'")->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['baseline-sub-1', $formId, '{}', 'agent', SubmissionStatus::EnCours->value]);
        $insert->execute(['baseline-sub-2', $formId, '{}', 'agent', SubmissionStatus::EnCours->value]);
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM submissions WHERE submitted_by = 'agent'")->fetchColumn());

        $statusSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='submissions'")->fetchColumn();
        self::assertStringContainsString('status TEXT NOT NULL', $statusSql);
        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO submissions (id, form_id, data, submitted_by, status) VALUES (?, ?, ?, ?, ?)')->execute(['bad', $formId, '{}', 'other', 'invalid']);
    }
}
