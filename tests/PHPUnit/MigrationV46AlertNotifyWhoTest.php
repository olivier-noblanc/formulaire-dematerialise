<?php
declare(strict_types=1);

namespace App\Tests;

use App\Enum\NotifyWho;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * v46 — contrainte SQL sur `alert_rules.notify_who`.
 *
 * Précondition métier : la colonne ne contient que les cinq sentinelles ou une
 * adresse email valide (FILTER_VALIDATE_EMAIL). La migration est rétrocompatible
 * (rebuild SQLite), refuse de s'appliquer si une ligne existante viole le
 * contrat (aucune normalisation, aucune perte silencieuse), et converge : une
 * base fraîche et une base migrée depuis v45 produisent le même schéma.
 */
final class MigrationV46AlertNotifyWhoTest extends TestCase
{
    /** @var list<string> */
    private array $dbPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->dbPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->dbPaths = [];
    }

    private function newDbPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'alert-notify-who-');
        self::assertNotFalse($path);
        $this->dbPaths[] = $path;
        return $path;
    }

    private function pdo(string $path): PDO
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** Base fraîche complètement migrée (baseline v42 + v43→v46). */
    private function migratedPdo(string $path): PDO
    {
        $pdo = $this->pdo($path);
        db_migrate($pdo);
        return $pdo;
    }

    /** Base migrée jusqu'à v45, donc encore dépourvue de la CHECK v46. */
    private function v45Pdo(string $path): PDO
    {
        $pdo = $this->pdo($path);
        $version = apply_schema_initial($pdo);
        $version = apply_migration_v43($pdo, $version);
        $version = apply_migration_v44($pdo, $version);
        $version = apply_migration_v45($pdo, $version);
        self::assertSame(45, $version);
        self::assertStringNotContainsString('notify_who IN', $this->alertRulesSql($pdo));
        return $pdo;
    }

    private function alertRulesSql(PDO $pdo): string
    {
        return (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='alert_rules'")->fetchColumn();
    }

    private function formId(PDO $pdo): string
    {
        return (string) $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding'")->fetchColumn();
    }

    private function ruleCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn();
    }

    private function insertRule(PDO $pdo, string $id, string $notifyWho): void
    {
        $pdo->prepare(
            'INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label) VALUES (?, ?, 5, \'steps_incomplete\', ?, ?)'
        )->execute([$id, $this->formId($pdo), $notifyWho, 'Règle ' . $id]);
    }

    public function testFreshDatabaseCarriesTheNotifyWhoCheck(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());

        self::assertSame(46, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
        self::assertStringContainsString('notify_who IN', $this->alertRulesSql($pdo));
        self::assertStringContainsString('LIKE', $this->alertRulesSql($pdo));
    }

    public function testFreshAndMigratedSchemasConverge(): void
    {
        $fresh = $this->migratedPdo($this->newDbPath());

        $migrated = $this->v45Pdo($this->newDbPath());
        $this->insertRule($migrated, 'rule-seed', NotifyWho::Admin->value);
        self::assertSame(46, apply_migration_v46($migrated, 45));

        self::assertSame($this->alertRulesSql($fresh), $this->alertRulesSql($migrated));
    }

    public function testCheckAcceptsSentinelsAndValidEmails(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());
        $before = $this->ruleCount($pdo);

        foreach (NotifyWho::values() as $index => $sentinel) {
            $this->insertRule($pdo, 'rule-' . $index, $sentinel);
        }
        $emails = ['manager@exemple.invalid', 'a@b.co', 'alertes.dreets@exemple.invalid', 'prenom.nom+alerte@exemple.invalid'];
        foreach ($emails as $index => $email) {
            $this->insertRule($pdo, 'mail-' . $index, $email);
        }

        self::assertSame($before + count(NotifyWho::values()) + count($emails), $this->ruleCount($pdo));
    }

    public function testCheckRejectsValuesOutsideTheContract(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());

        foreach (['foo', 'custom', '', 'admin + submitter', 'not-an-email', 'foo@'] as $index => $invalid) {
            try {
                $this->insertRule($pdo, 'bad-' . $index, $invalid);
                self::fail("La CHECK v46 doit refuser notify_who = '{$invalid}'.");
            } catch (\PDOException $exception) {
                self::assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }
    }

    public function testPreflightBlocksInvalidLegacyRowsWithoutNormalisation(): void
    {
        $pdo = $this->v45Pdo($this->newDbPath());
        $before = $this->ruleCount($pdo);
        $this->insertRule($pdo, 'legacy-ok', NotifyWho::Submitter->value);
        $this->insertRule($pdo, 'legacy-bad', 'admin + submitter');

        try {
            apply_migration_v46($pdo, 45);
            self::fail('La v46 doit échouer sur une ligne existante invalide.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('v46 blocked', $exception->getMessage());
        }

        // Aucune normalisation, aucune perte : la ligne reste telle quelle.
        $row = $pdo->query("SELECT notify_who FROM alert_rules WHERE id = 'legacy-bad'")->fetchColumn();
        self::assertSame('admin + submitter', $row);
        self::assertSame($before + 2, $this->ruleCount($pdo));
        // Rollback : version et schéma inchangés.
        self::assertSame(45, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 46')->fetchColumn());
        self::assertStringNotContainsString('notify_who IN', $this->alertRulesSql($pdo));
    }

    public function testPreflightSucceedsOnceTheInvalidRowIsFixed(): void
    {
        $pdo = $this->v45Pdo($this->newDbPath());
        $before = $this->ruleCount($pdo);
        $this->insertRule($pdo, 'legacy-bad', 'admin + submitter');
        $pdo->exec("UPDATE alert_rules SET notify_who = 'validators' WHERE id = 'legacy-bad'");

        self::assertSame(46, apply_migration_v46($pdo, 45));
        self::assertSame($before + 1, $this->ruleCount($pdo));
        self::assertStringContainsString('notify_who IN', $this->alertRulesSql($pdo));
    }

    public function testMigrationIsIdempotent(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());

        self::assertSame(46, apply_migration_v46($pdo, 46));
        db_migrate($pdo);

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 46')->fetchColumn());
        self::assertSame(46, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
    }
}