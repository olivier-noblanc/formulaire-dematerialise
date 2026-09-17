<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BackupController;
use App\Core\App;
use App\Core\Database;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * BUG6 (audit 2026-09-17) — BackupController purge_confirm doit être atomique.
 *
 * Avant le correctif, les 4 suppressions (submission_validator_data, alert_log,
 * tokens, submissions) s'exécutaient sans transaction : une erreur en cours de
 * route laissait la base à moitié purgée, et seul \Exception était intercepté
 * (un \Error remontait en laissant l'état partiel). Le correctif ouvre une
 * transaction (BEGIN IMMEDIATE, BUG3), rollback sur tout \Throwable, puis
 * exécute VACUUM et l'audit `purge_data` APRÈS le commit.
 *
 * Fichier dédié (et non BackupControllerTest) pour isoler ce contrat.
 */
final class BackupPurgeConfirmTransactionTest extends TestCase
{
    private Database $db;

    /** @var list<string> */
    private array $createdSubmissionIds = [];
    /** @var list<string> */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_SERVER['AUTH_USER'] = 'DREETS\testeur';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/';
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $GLOBALS['_test_mails'] = [];
        $GLOBALS['_test_captured_json'] = null;

        $this->addAdmin('testeur@e2e.test');

        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-bpt-%')");
        $pdo->exec("DELETE FROM submissions WHERE submitted_by LIKE 'test-bpt-%'");
        $pdo->exec("DELETE FROM forms WHERE slug LIKE 'test-bpt-%'");
        $pdo->exec("DELETE FROM audit_log WHERE action = 'purge_data'");
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdSubmissionIds as $id) {
            try {
                $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {
            }
        }
        foreach ($this->createdFormIds as $id) {
            try {
                $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$id]);
            } catch (\Throwable) {
            }
        }
        $pdo->exec("DELETE FROM audit_log WHERE action = 'purge_data'");
        $this->createdSubmissionIds = [];
        $this->createdFormIds = [];
    }

    /**
     * Une erreur pendant la suppression des soumissions (APRÈS que
     * validator_data/alert_log/tokens aient été supprimés) doit tout annuler :
     * rollback, message d'erreur, aucune entrée d'audit `purge_data`.
     */
    public function testPurgeConfirmRollsBackOnThrowableAndSkipsPostCommitWork(): void
    {
        $oldSubId = $this->createOldClosedSubmission(13);
        $pdo = $this->db->getPdo();
        $pdo->prepare(
            "INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at)
             VALUES (?, ?, 'decision', 'Décision', 'text', 'ok', 'validator', datetime('now'))"
        )->execute([\generate_uuid(), $oldSubId]);

        $db = App::getInstance()->get(Database::class);
        $db->getPdo(); // force l'init de la connexion test
        $pdoTestProp = new \ReflectionProperty(Database::class, 'pdoTest');
        $originalPdo = $pdoTestProp->getValue($db);
        self::assertInstanceOf(\PDO::class, $originalPdo);

        $testDbPath = (string) ($GLOBALS['_test_db_path'] ?? dirname(__DIR__, 3) . '/db/workflow_test.db');
        $throwingPdo = new class ('sqlite:' . $testDbPath) extends \PDO {
            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $this->exec('PRAGMA foreign_keys = ON');
                $this->exec('PRAGMA busy_timeout = 5000');
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'DELETE FROM submissions')) {
                    throw new \RuntimeException('Panne simulée pendant la purge');
                }
                return parent::prepare($query, $options);
            }
        };

        $pdoTestProp->setValue($db, $throwingPdo);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_confirm', 'csrf_token' => 'test', 'purge_months' => '12'];

        try {
            $output = $this->captureOutput(fn() => new BackupController()->handle());

            self::assertStringContainsString('Une erreur technique est survenue', $output);
            self::assertStringNotContainsString('Purge effectuée avec succès', $output);

            // Vérification hors du PDO double (connexion directe sur le fichier).
            $check = new \PDO('sqlite:' . $testDbPath);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::assertSame(
                1,
                (int) $check->query("SELECT COUNT(*) FROM submissions WHERE id = " . $check->quote($oldSubId))->fetchColumn(),
                'BUG6 : le rollback doit conserver la soumission.'
            );
            self::assertSame(
                1,
                (int) $check->query("SELECT COUNT(*) FROM submission_validator_data WHERE submission_id = " . $check->quote($oldSubId))->fetchColumn(),
                'BUG6 : les suppressions déjà effectuées dans la transaction doivent être annulées par le rollback.'
            );
            self::assertSame(
                0,
                (int) $check->query("SELECT COUNT(*) FROM audit_log WHERE action = 'purge_data'")->fetchColumn(),
                'BUG6 : aucune purge committée → pas d\'audit purge_data.'
            );
            $check = null;
        } finally {
            $pdoTestProp->setValue($db, $originalPdo);
            $throwingPdo = null;
        }
    }

    /**
     * Cas nominal (non-régression) : une purge réussie reste atomique et trace
     * l'audit `purge_data` après le commit.
     */
    public function testPurgeConfirmCommitsDeletionsAndLogsAfterCommit(): void
    {
        $oldSubId = $this->createOldClosedSubmission(13);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'purge_confirm', 'csrf_token' => 'test', 'purge_months' => '12'];

        $output = $this->captureOutput(fn() => new BackupController()->handle());

        self::assertStringContainsString('Purge effectuée avec succès', $output);

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM submissions WHERE id = ?');
        $stmt->execute([$oldSubId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'la soumission purgeable doit être supprimée et committée.');

        $auditStmt = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'purge_data'");
        self::assertSame(1, (int) $auditStmt->fetchColumn(), 'l\'audit purge_data doit être écrit après le commit.');

        $this->createdSubmissionIds = array_diff($this->createdSubmissionIds, [$oldSubId]);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function addAdmin(string $email): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([\generate_uuid(), $email]);
    }

    private function createTestForm(string $slug): string
    {
        $pdo = $this->db->getPdo();
        $formId = \generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'BPT Test Form', '', 1, datetime('now'))")
            ->execute([$formId, $slug]);
        $this->createdFormIds[] = $formId;

        return $formId;
    }

    private function createOldClosedSubmission(int $monthsAgo): string
    {
        $pdo = $this->db->getPdo();
        $formId = $this->createTestForm('test-bpt-old-' . uniqid());

        $subId = \generate_uuid();
        $submittedAt = gmdate('Y-m-d H:i:s', strtotime("-{$monthsAgo} months"));
        $closedAt = gmdate('Y-m-d H:i:s', strtotime("-{$monthsAgo} months +1 hour"));
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status, rgpd_consent) "
            . "VALUES (?, ?, '{}', 'test-bpt-agent@e2e.test', ?, ?, 'valide', 1)"
        )->execute([$subId, $formId, $submittedAt, $closedAt]);
        $this->createdSubmissionIds[] = $subId;

        return $subId;
    }

    /**
     * @param callable(): void $callable
     */
    private function captureOutput(callable $callable): string
    {
        ob_start();
        try {
            $callable();
        } catch (TestJsonCapturedException) {
            // JSON capturé — on continue
        } finally {
            $output = ob_get_clean();
        }

        return (string) $output;
    }
}