<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Cron\CronService;
use App\Core\Database;

final class CronServiceTest extends TestCase
{
    private CronService $cron;
    private Database $db;

    protected function setUp(): void
    {
        // Reset static guard from previous test so runLazyCron() actually executes
        $ref = new \ReflectionProperty(CronService::class, 'running');
        $ref->setValue(null, false);

        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->cron = new CronService($this->db);

        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM lazy_cron");
    }

    private function resetRunningGuard(): void
    {
        $ref = new \ReflectionProperty(CronService::class, 'running');
        $ref->setValue(null, false);
    }

    // ── Constructor / DI ───────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        self::assertInstanceOf(CronService::class, $this->cron);
    }

    public function testServiceRegistrableInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        self::assertTrue($app->has(CronService::class));
    }

    public function testAppCronAccessorReturnsCronService(): void
    {
        $cron = \App\Core\App::cron();
        self::assertInstanceOf(CronService::class, $cron);
    }

    public function testAppCronReturnsSameInstance(): void
    {
        $cron1 = \App\Core\App::cron();
        $cron2 = \App\Core\App::cron();
        self::assertSame($cron1, $cron2);
    }

    // ── parseDbDatetime ─────────────────────────────────────────

    public function testParseDbDatetimeReturnsTimestampForValidDatetime(): void
    {
        $result = CronService::parseDbDatetime('2025-01-15 10:30:00');
        self::assertIsInt($result);
        self::assertSame(1736937000, $result);
    }

    public function testParseDbDatetimeReturnsNullForInvalidDatetime(): void
    {
        $result = CronService::parseDbDatetime('not-a-date');
        self::assertNull($result);
    }

    public function testParseDbDatetimeReturnsNullForEmptyString(): void
    {
        $result = CronService::parseDbDatetime('');
        self::assertNull($result);
    }

    public function testParseDbDatetimeHandlesEpochZero(): void
    {
        $result = CronService::parseDbDatetime('1970-01-01 00:00:00');
        self::assertSame(0, $result);
    }

    public function testParseDbDatetimeHandlesFarFutureDate(): void
    {
        $result = CronService::parseDbDatetime('2099-12-31 23:59:59');
        self::assertIsInt($result);
        self::assertGreaterThan(time(), $result);
    }

    public function testParseDbDatetimeHandlesLeapYearDate(): void
    {
        $result = CronService::parseDbDatetime('2024-02-29 12:00:00');
        self::assertIsInt($result);
    }

    public function testParseDbDatetimeReturnsNullForPartialDate(): void
    {
        $result = CronService::parseDbDatetime('2025-01-15');
        self::assertNull($result);
    }

    public function testParseDbDatetimeReturnsNullForDateWithMilliseconds(): void
    {
        $result = CronService::parseDbDatetime('2025-01-15 10:30:00.123456');
        self::assertNull($result);
    }

    public function testParseDbDatetimeHandlesMidnight(): void
    {
        $result = CronService::parseDbDatetime('2025-06-15 00:00:00');
        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testParseDbDatetimeHandlesEndOfDay(): void
    {
        $result = CronService::parseDbDatetime('2025-06-15 23:59:59');
        self::assertIsInt($result);
    }

    // ── runLazyCron ─────────────────────────────────────────────

    public function testRunLazyCronCreatesDbRowsOnFirstRun(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT task_key, run_count FROM lazy_cron ORDER BY task_key");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertNotEmpty($rows);
        $keys = array_column($rows, 'task_key');
        self::assertContains('remind', $keys);
        self::assertContains('alert_check', $keys);
        self::assertContains('rgpd_purge', $keys);
        self::assertContains('mail_outbox', $keys);
    }

    public function testRunLazyCronSkipsTasksWithinInterval(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $stmt1 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count1 = (int)$stmt1->fetchColumn();

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt2 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count2 = (int)$stmt2->fetchColumn();

        self::assertSame($count1, $count2);
    }

    public function testRunLazyCronRunsTaskWhenIntervalElapsed(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'remind'")
            ->execute([$twoHoursAgo]);

        $stmt1 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count1 = (int)$stmt1->fetchColumn();

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt2 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count2 = (int)$stmt2->fetchColumn();

        self::assertGreaterThan($count1, $count2);
    }

    public function testRunLazyCronHandlesConcurrentReentry(): void
    {
        $this->cron->runLazyCron();
        $this->cron->runLazyCron(); // should be silently skipped

        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM lazy_cron");
        self::assertGreaterThanOrEqual(1, (int)$stmt->fetchColumn());
    }

    /**
     * F1 : la garde statique doit bloquer toute réentrance dans le même process,
     * indépendamment de l'intervalle (ici remind est bien dû, mais la 2e entrée
     * est ignorée).
     */
    public function testRunLazyCronGuardBlocksReentryEvenWhenTaskIsDue(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'remind'")
            ->execute([$twoHoursAgo]);

        $stmt = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $countBefore = (int) $stmt->fetchColumn();
        $stmt = null;

        // Réentrée SANS reset du garde : ignorée même si la tâche est due.
        $this->cron->runLazyCron();

        $stmt = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $countAfter = (int) $stmt->fetchColumn();

        self::assertSame(
            $countBefore,
            $countAfter,
            'F1 : la garde statique doit bloquer la réentrée même quand une tâche est due'
        );
    }

    /**
     * F1 : une exception dans un callback de tâche est contenue (B9 : last_run
     * revert) et NE libère PAS la garde — la réentrée reste bloquée pour ce process.
     */
    public function testRunLazyCronCallbackExceptionDoesNotReleaseGuard(): void
    {
        $app = \App\Core\App::getInstance();
        $original = $app->get(\App\Rgpd\RgpdService::class);

        $throwing = new class {
            public function autoPurge(): never
            {
                throw new \RuntimeException('boom rgpd_purge');
            }
        };
        $app->set(\App\Rgpd\RgpdService::class, $throwing);

        try {
            // L'exception doit être contenue par le service (pas de propagation).
            $this->cron->runLazyCron();
        } finally {
            $app->set(\App\Rgpd\RgpdService::class, $original);
        }

        $ref = new \ReflectionProperty(CronService::class, 'running');
        self::assertTrue(
            (bool) $ref->getValue(),
            'F1 : une exception de callback ne doit pas libérer la garde statique'
        );

        // B9 : première exécution en échec → la ligne rgpd_purge est supprimée
        // (elle redevient due au prochain process).
        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM lazy_cron WHERE task_key = 'rgpd_purge'");
        $count = (int) $stmt->fetchColumn();
        self::assertSame(0, $count, 'B9 : rgpd_purge doit être revert (ligne supprimée) après échec');
    }

    public function testRunLazyCronIncrementsRunCount(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count = (int) $stmt->fetchColumn();
        self::assertGreaterThanOrEqual(1, $count);
    }

    public function testRunLazyCronSetsLastRunTimestamp(): void
    {
        $before = gmdate('Y-m-d H:i:s');
        $this->cron->runLazyCron();
        $after = gmdate('Y-m-d H:i:s');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT last_run FROM lazy_cron WHERE task_key = 'remind'");
        $lastRun = (string) $stmt->fetchColumn();

        self::assertGreaterThanOrEqual($before, $lastRun);
        self::assertLessThanOrEqual($after, $lastRun);
    }

    public function testRunLazyCronCreatesAllTaskKeys(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT task_key FROM lazy_cron ORDER BY task_key");
        $keys = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(4, $keys);
        self::assertContains('alert_check', $keys);
        self::assertContains('remind', $keys);
        self::assertContains('rgpd_purge', $keys);
        self::assertContains('mail_outbox', $keys);
    }

    public function testRunLazyCronHandlesMailOutboxInterval(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        // mail_outbox interval = 300s : reculer last_run de 10 min pour qu'il soit dû.
        $tenMinAgo = gmdate('Y-m-d H:i:s', time() - 600);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'mail_outbox'")
            ->execute([$tenMinAgo]);

        $stmt1 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'mail_outbox'");
        $count1 = (int) $stmt1->fetchColumn();

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt2 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'mail_outbox'");
        $count2 = (int) $stmt2->fetchColumn();

        self::assertGreaterThan($count1, $count2);
    }

    public function testRunLazyCronHandlesAlertCheckInterval(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        // Fake last_run to 2 days ago so 'alert_check' (86400s) is due
        $twoDaysAgo = gmdate('Y-m-d H:i:s', time() - 172800);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'alert_check'")
            ->execute([$twoDaysAgo]);

        $stmt1 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'alert_check'");
        $count1 = (int)$stmt1->fetchColumn();

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt2 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'alert_check'");
        $count2 = (int)$stmt2->fetchColumn();

        self::assertGreaterThan($count1, $count2);
    }

    public function testRunLazyCronHandlesRgpdPurgeInterval(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $twoDaysAgo = gmdate('Y-m-d H:i:s', time() - 172800);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'rgpd_purge'")
            ->execute([$twoDaysAgo]);

        $stmt1 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'rgpd_purge'");
        $count1 = (int)$stmt1->fetchColumn();

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt2 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'rgpd_purge'");
        $count2 = (int)$stmt2->fetchColumn();

        self::assertGreaterThan($count1, $count2);
    }

    public function testRunLazyCronDoesNotRunRemindWhenRecentlyRun(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        // Set last_run to 30 minutes ago (less than remind interval of 3600s)
        $thirtyMinAgo = gmdate('Y-m-d H:i:s', time() - 1800);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'remind'")
            ->execute([$thirtyMinAgo]);

        $stmt1 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count1 = (int)$stmt1->fetchColumn();

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt2 = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count2 = (int)$stmt2->fetchColumn();

        self::assertSame($count1, $count2);
    }

    // ── Edge cases ──────────────────────────────────────────────

    public function testParseDbDatetimeHandlesNewYearsEve(): void
    {
        $result = CronService::parseDbDatetime('2025-12-31 23:59:59');
        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testParseDbDatetimeHandlesNewYearsDay(): void
    {
        $result = CronService::parseDbDatetime('2025-01-01 00:00:00');
        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testRunLazyCronIdempotentWhenAllTasksRecentlyRun(): void
    {
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $stmt = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count1 = (int) $stmt->fetchColumn();

        // Run again immediately — all tasks within interval
        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt = $pdo->query("SELECT run_count FROM lazy_cron WHERE task_key = 'remind'");
        $count2 = (int) $stmt->fetchColumn();

        self::assertSame($count1, $count2);
    }

    public function testRunLazyCronUsesInsertOrReplace(): void
    {
        // Run twice with elapsed interval — should use INSERT OR REPLACE, not duplicate
        $this->cron->runLazyCron();

        $pdo = $this->db->getPdo();
        $oldTimestamp = gmdate('Y-m-d H:i:s', time() - 7200);
        $pdo->prepare("UPDATE lazy_cron SET last_run = ? WHERE task_key = 'remind'")
            ->execute([$oldTimestamp]);

        $this->resetRunningGuard();
        $this->cron->runLazyCron();

        $stmt = $pdo->query("SELECT COUNT(*) FROM lazy_cron WHERE task_key = 'remind'");
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
