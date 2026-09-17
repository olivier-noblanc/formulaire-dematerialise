<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MonitoringController;
use App\Core\App;
use App\Core\Database;
use App\Enum\SubmissionStatus;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * BUG4 (audit 2026-09-17) — « days_remaining » de la carte « Alertes actives »
 * de la page Surveillance.
 *
 * L'ancien calcul floor(($deadlineTs - time()) / 86400) tronquait en périodes
 * de 24h pleines : deadline demain 00:00 vue à 15:00 → 0 (Jour J au lieu de
 * J-1) et le jour J vu après minuit → -1 (faux retard « J+1 »). Le calcul doit
 * déléguer à DateHelper::parseDate() + DateHelper::calendarDaysUntil() (jours
 * calendaires Europe/Paris), source unique déjà utilisée par
 * calculateDeadlineUrgency() et alert_check.php.
 *
 * Deux niveaux de couverture :
 *  - le calcul pur (J0 / J+1 / retard), déterministe via $now injecté ;
 *  - le câblage de handle() qui doit rendre les libellés « Jour J », « J-1 »
 *    et « J+1 » (template monitoring_active_alerts.php).
 */
final class MonitoringDeadlineDaysTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_SERVER['AUTH_USER'] = 'DREETS\\testeur';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/?p=monitoring';
        $_GET = [];
        $_POST = [];
        $GLOBALS['_test_captured_json'] = null;

        $this->addAdmin('testeur@e2e.test');
        $this->cleanupFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        // Ne PAS retirer l'admin seedé par phpunit_bootstrap (contaminerait les
        // autres classes de tests) — on le réinsère par sécurité.
        $this->addAdmin('testeur@e2e.test');
        $GLOBALS['_test_captured_json'] = null;
    }

    // ── Calcul pur : J0 / J+1 / retard (déterministe, $now injecté) ─

    public function testDeadlineTodayIsZeroNotOverdue(): void
    {
        $now = new \DateTimeImmutable('2026-09-03 15:00:00', new \DateTimeZone('Europe/Paris'));

        $info = MonitoringController::computeDeadlineInfo('2026-09-03', $now);

        self::assertNotNull($info);
        self::assertSame(0, $info['days_remaining'], 'Le jour J doit valoir 0 (Jour J), pas -1');
        self::assertSame('03/09/2026', $info['deadline_formatted']);
    }

    public function testDeadlineTomorrowIsOneEvenLateInDay(): void
    {
        $now = new \DateTimeImmutable('2026-09-03 23:30:00', new \DateTimeZone('Europe/Paris'));

        $info = MonitoringController::computeDeadlineInfo('2026-09-04', $now);

        self::assertNotNull($info);
        self::assertSame(1, $info['days_remaining'], 'Une deadline demain doit valoir 1 (J-1), pas 0');
    }

    public function testDeadlineYesterdayIsMinusOneOverdue(): void
    {
        $now = new \DateTimeImmutable('2026-09-03 15:00:00', new \DateTimeZone('Europe/Paris'));

        $info = MonitoringController::computeDeadlineInfo('2026-09-02', $now);

        self::assertNotNull($info);
        self::assertSame(-1, $info['days_remaining'], 'Une deadline dépassée doit valoir -1 (retard), pas 0');
    }

    public function testDeadlineAcceptsFrenchFormat(): void
    {
        $now = new \DateTimeImmutable('2026-09-03 15:00:00', new \DateTimeZone('Europe/Paris'));

        $info = MonitoringController::computeDeadlineInfo('04/09/2026', $now);

        self::assertNotNull($info);
        self::assertSame(1, $info['days_remaining']);
        self::assertSame('04/09/2026', $info['deadline_formatted']);
    }

    public function testInvalidDateReturnsNull(): void
    {
        self::assertNull(MonitoringController::computeDeadlineInfo('pas-une-date'));
        self::assertNull(MonitoringController::computeDeadlineInfo('30/02/2026'));
        self::assertNull(MonitoringController::computeDeadlineInfo(''));
    }

    public function testDeadlineFormattedIgnoresServerTimezone(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14
        try {
            $info = MonitoringController::computeDeadlineInfo('2026-09-03');
        } finally {
            date_default_timezone_set($previousTz);
        }

        self::assertNotNull($info);
        self::assertSame(
            '03/09/2026',
            $info['deadline_formatted'],
            'La date cible est une date Paris ; le fuseau serveur ne doit pas la décaler'
        );
    }

    // ── Câblage handle() : libellés calendaires rendus ──

    /**
     * Le template rend « Jour J » (J0), « J-1 » (demain) et « J+1 » (retard).
     * L'ancien floor(Δt/86400) produisait respectivement « J+1 », « Jour J »
     * et « J+1 » : le « Jour J » attendu pour aujourd'hui serait donc absent.
     */
    public function testHandleRendersCalendarLabelsForTodayTomorrowAndOverdue(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $today = new \DateTimeImmutable('now', $paris);

        $this->seedDeadlineSubmission($today->format('Y-m-d'));                      // J0 → Jour J
        $this->seedDeadlineSubmission($today->modify('+1 day')->format('Y-m-d'));    // demain → J-1
        $this->seedDeadlineSubmission($today->modify('-1 day')->format('Y-m-d'));    // hier → J+1

        $output = $this->captureOutput(fn() => new MonitoringController()->handle());

        self::assertStringContainsString('class="days-remaining critical">Jour J</span>', $output);
        self::assertStringContainsString('class="days-remaining critical">J-1</span>', $output);
        self::assertStringContainsString('class="days-remaining overdue">J+1</span>', $output);
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function seedDeadlineSubmission(string $deadline): void
    {
        $pdo = $this->db->getPdo();

        $formId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field)
             VALUES (?, ?, 'MC Deadline Test', '', 1, datetime('now'), 'date_cible')"
        )->execute([$formId, 'test-mc-' . uniqid()]);

        $subId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status, rgpd_consent)
             VALUES (?, ?, ?, 'test-mc-agent@e2e.test', datetime('now'), ?, 1)"
        )->execute([$subId, $formId, json_encode(['date_cible' => $deadline]), SubmissionStatus::EnCours->value]);
    }

    private function cleanupFixtures(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-mc-agent@%')");
        $pdo->exec("DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE submitted_by LIKE 'test-mc-agent@%')");
        $pdo->exec("DELETE FROM submissions WHERE submitted_by LIKE 'test-mc-agent@%'");
        $pdo->exec("DELETE FROM forms WHERE slug LIKE 'test-mc-%'");
    }

    private function addAdmin(string $email): void
    {
        $stmt = $this->db->getPdo()->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))");
        $stmt->execute([\generate_uuid(), $email]);
    }

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