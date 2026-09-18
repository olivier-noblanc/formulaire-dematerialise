<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Repository\SubmissionRepository;
use PHPUnit\Framework\TestCase;

/**
 * P2-E — référentiel de temps unique (UTC) pour les statistiques.
 *
 * Avant P2-E, `submissions.submitted_at` était écrit en heure locale de Paris
 * (PHP date() sous Europe/Paris) alors que `closed_at` était en UTC, ce qui
 * faussait durées et compteurs. La migration v40 a converti l'historique et
 * les writers utilisent désormais gmdate() : les deux bornes sont en UTC.
 *
 * Ces tests vérifient que les calculs sont exacts et indépendants du fuseau
 * système du serveur (forcé ici sur Europe/Paris, comme la prod) :
 *  - durées de traitement = différence de deux instants UTC (été/hiver) ;
 *  - compteurs today/week/month et fenêtre daily calculés en UTC (gmdate).
 *
 * Base SQLite temporaire isolée (schéma migré).
 */
final class SubmissionStatsTimezoneTest extends TestCase
{
    private string $dbPath;
    private ?string $savedTestDbPath = null;
    private string $savedTz;
    private Database $db;
    private SubmissionRepository $repo;

    protected function setUp(): void
    {
        $this->savedTestDbPath = $GLOBALS['_test_db_path'] ?? null;
        $this->savedTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        $this->dbPath = tempnam(sys_get_temp_dir(), 'stats_tz_');
        self::assertNotFalse($this->dbPath);
        $GLOBALS['_test_db_path'] = $this->dbPath;
        $this->db = new Database();
        // Force l'ouverture → db_migrate() construit le schéma complet.
        self::assertInstanceOf(\PDO::class, $this->db->getPdo());
        $this->repo = new SubmissionRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->release();
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        date_default_timezone_set($this->savedTz);
        if ($this->savedTestDbPath === null) {
            unset($GLOBALS['_test_db_path']);
        } else {
            $GLOBALS['_test_db_path'] = $this->savedTestDbPath;
        }
    }

    // ─ Durées : soustraction de deux instants UTC ──────────────────

    /**
     * submitted_at et closed_at sont tous deux en UTC (P2-E).
     *  - été   : submitted 08:00 UTC, closed 11:00 UTC → 3 h (10800 s) ;
     *  - hiver : submitted 09:00 UTC, closed 11:00 UTC → 2 h (7200 s).
     * moyenne = 9000 s.
     */
    public function testAverageProcessingSecondsIsExactWithUtcStorage(): void
    {
        $year = (int) date('Y');
        $formId = $this->insertForm('stats-tz-durations');

        $this->insertClosed($formId, $year . '-07-01 08:00:00', $year . '-07-01 11:00:00', 'summer@test.stats');
        $this->insertClosed($formId, $year . '-01-15 09:00:00', $year . '-01-15 11:00:00', 'winter@test.stats');

        self::assertEqualsWithDelta(9000.0, $this->repo->getAvgProcessingSeconds(), 0.001);
        // Alias historique (MonitoringController) : même résultat.
        self::assertEqualsWithDelta(9000.0, $this->repo->getAvgProcessingTime(), 0.001);

        $formStats = $this->repo->getFormStats();
        $row = $this->findFormRow($formStats, 'stats-tz-durations');
        self::assertNotNull($row);
        self::assertEqualsWithDelta(9000.0, (float) $row['avg_seconds'], 0.001);
        self::assertSame(2, (int) $row['valide']);
    }

    public function testStatsByPeriodAverageIsExactWithUtcStorage(): void
    {
        $year = (int) date('Y');
        $formId = $this->insertForm('stats-tz-period');

        $this->insertClosed($formId, $year . '-07-01 08:00:00', $year . '-07-01 11:00:00', 'p-summer@test.stats');
        $this->insertClosed($formId, $year . '-01-15 09:00:00', $year . '-01-15 11:00:00', 'p-winter@test.stats');

        $periods = $this->repo->getStatsByPeriod('%Y-%m', '-12 months', 12);

        $byPeriod = [];
        foreach ($periods as $p) {
            $byPeriod[(string) $p['period']] = $p;
        }

        self::assertArrayHasKey($year . '-07', $byPeriod);
        self::assertArrayHasKey($year . '-01', $byPeriod);
        self::assertEqualsWithDelta(10800.0, (float) $byPeriod[$year . '-07']['avg_processing_seconds'], 0.001);
        self::assertEqualsWithDelta(7200.0, (float) $byPeriod[$year . '-01']['avg_processing_seconds'], 0.001);
    }

    // ─ Compteurs : bornes calculées en UTC ─────────────────────────

    public function testGlobalCountsWeekAndMonthUseUtcReference(): void
    {
        $formId = $this->insertForm('stats-tz-counts');

        // Hors fenêtre : 7 j + 1 h et 30 j + 1 h dans le passé (UTC).
        $this->insertClosed($formId, $this->utc('-7 days', -3600), $this->utc('-7 days', -3600), 'out-week@test.stats');
        $this->insertClosed($formId, $this->utc('-30 days', -3600), $this->utc('-30 days', -3600), 'out-month@test.stats');

        // Dans la fenêtre semaine/mois.
        $this->insertClosed($formId, $this->utc('-6 days'), $this->utc('-6 days'), 'in-week@test.stats');

        // Aujourd'hui (date UTC).
        $now = $this->utc('now');
        $this->insertClosed($formId, $now, $now, 'today@test.stats');

        $counts = $this->repo->getGlobalStatsCounts();

        self::assertSame(1, (int) $counts['today'], 'une soumission de maintenant doit compter dans today (date UTC)');
        self::assertSame(2, (int) $counts['this_week'], 'seules les lignes à -6 j et maintenant sont dans this_week');
        self::assertSame(3, (int) $counts['this_month'], 'seules les lignes à -7 j, -6 j et maintenant sont dans this_month');
    }

    public function testDailyCountsUsesUtcReferenceWindow(): void
    {
        $formId = $this->insertForm('stats-tz-daily');

        // Hors fenêtre (7 j + 1 h).
        $this->insertClosed($formId, $this->utc('-7 days', -3600), $this->utc('-7 days', -3600), 'daily-out@test.stats');
        $this->insertClosed($formId, $this->utc('-2 days'), $this->utc('-2 days'), 'daily-in@test.stats');

        $daily = $this->repo->getDailyCounts(7);
        $total = 0;
        foreach ($daily as $day) {
            $total += (int) $day['cnt'];
        }
        self::assertSame(1, $total, 'seule la soumission à -2 j doit rester dans la fenêtre de 7 jours');
    }

    // ── Fixtures ────────────────────────────────────────────────────

    /**
     * Horodatage en UTC (comme FormSubmissionHandler / gmdate()), décalé d'une
     * durée relative — ex. utc('-7 days', -3600).
     */
    private function utc(string $modifier, int $offsetSeconds = 0): string
    {
        $ts = strtotime($modifier);
        return gmdate('Y-m-d H:i:s', ($ts !== false ? $ts : time()) + $offsetSeconds);
    }

    private function insertForm(string $slug): string
    {
        $id = \generate_uuid();
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))"
        );
        $stmt->execute([$id, $slug, 'Test Stats TZ']);
        return $id;
    }

    private function insertClosed(string $formId, string $submittedAt, string $closedAt, string $email): void
    {
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status, rgpd_consent)
             VALUES (?, ?, '{}', ?, ?, ?, 'valide', 1)"
        );
        $stmt->execute([\generate_uuid(), $formId, $email, $submittedAt, $closedAt]);
    }

    /**
     * @param list<array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}> $rows
     * @return array{label: string, slug: string, total: int|string, en_cours: int|string, valide: int|string, refuse: int|string, avg_seconds: float|string|null}|null
     */
    private function findFormRow(array $rows, string $slug): ?array
    {
        foreach ($rows as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }
        return null;
    }
}