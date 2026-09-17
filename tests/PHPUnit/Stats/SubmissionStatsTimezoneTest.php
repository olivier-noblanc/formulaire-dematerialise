<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Repository\SubmissionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Bug confirmé — SubmissionStatsTrait mélangeait deux référentiels de temps :
 * submissions.submitted_at est écrit en heure locale de Paris (PHP date() sous
 * Europe/Paris, voir config.php) alors que closed_at est en UTC (SQLite
 * datetime('now') / PHP gmdate()).
 *
 * Conséquences testées ici :
 *  - durées de traitement (AVG closed_at - submitted_at) sous-estimées de 1 à 2 h
 *    selon la saison (heure d'été / d'hiver) ;
 *  - compteurs today/week/month faussés car submitted_at (Paris) était comparé à
 *    `datetime('now')` (UTC).
 *
 * Les tests tournent sur une base SQLite temporaire isolée (schéma migré) pour
 * ne pas dépendre de la base partagée de la suite, et forcent le fuseau PHP sur
 * Europe/Paris (comme la prod) : la correction doit alors être déterministe
 * quel que soit le fuseau système de la machine (UTC sur la CI).
 */
final class SubmissionStatsTimezoneTest extends TestCase
{
    private string $dbPath;
    private ?string $savedTestDbPath = null;
    private string $savedTz;
    private Database $db;
    private SubmissionRepository $repo;
    private int $seq = 0;

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

    // ─ Durées : conversion Paris → UTC ─────────────────────────────

    /**
     * submitted_at en heure de Paris, closed_at en UTC.
     *  - été   : 2026-07-01 10:00 Paris = 08:00 UTC → closed 11:00 UTC = 3 h (10800 s)
     *  - hiver : 2026-01-15 10:00 Paris = 09:00 UTC → closed 11:00 UTC = 2 h (7200 s)
     * moyenne = 9000 s ; l'ancien calcul (soustraction des epochs « bruts »)
     * donnait 3600 s (durée affichée systématiquement 1 h trop courte).
     */
    public function testAverageProcessingSecondsCorrectsParisVersusUtcAcrossSeasons(): void
    {
        $year = (int) date('Y');
        $formId = $this->insertForm('stats-tz-durations');

        $this->insertClosed($formId, $year . '-07-01 10:00:00', $year . '-07-01 11:00:00', 'summer@test.stats');
        $this->insertClosed($formId, $year . '-01-15 10:00:00', $year . '-01-15 11:00:00', 'winter@test.stats');

        self::assertEqualsWithDelta(9000.0, $this->repo->getAvgProcessingSeconds(), 0.001);
        // Alias historique (MonitoringController) : même résultat.
        self::assertEqualsWithDelta(9000.0, $this->repo->getAvgProcessingTime(), 0.001);

        $formStats = $this->repo->getFormStats();
        $row = $this->findFormRow($formStats, 'stats-tz-durations');
        self::assertNotNull($row);
        self::assertEqualsWithDelta(9000.0, (float) $row['avg_seconds'], 0.001);
        self::assertSame(2, (int) $row['valide']);
    }

    public function testStatsByPeriodAverageCorrectsParisVersusUtc(): void
    {
        $year = (int) date('Y');
        $formId = $this->insertForm('stats-tz-period');

        $this->insertClosed($formId, $year . '-07-01 10:00:00', $year . '-07-01 11:00:00', 'p-summer@test.stats');
        $this->insertClosed($formId, $year . '-01-15 10:00:00', $year . '-01-15 11:00:00', 'p-winter@test.stats');

        $periods = $this->repo->getStatsByPeriod('%Y-%m', '-12 months', 12);

        $byPeriod = [];
        foreach ($periods as $p) {
            $byPeriod[(string) $p['period']] = $p;
        }

        self::assertArrayHasKey($year . '-07', $byPeriod);
        self::assertArrayHasKey($year . '-01', $byPeriod);
        // Été : 3 h réelles ; hiver : 2 h réelles.
        self::assertEqualsWithDelta(10800.0, (float) $byPeriod[$year . '-07']['avg_processing_seconds'], 0.001);
        self::assertEqualsWithDelta(7200.0, (float) $byPeriod[$year . '-01']['avg_processing_seconds'], 0.001);
    }

    // ─ Compteurs : bornes calculées en heure de Paris ──────────────

    public function testGlobalCountsWeekAndMonthUseParisReference(): void
    {
        $formId = $this->insertForm('stats-tz-counts');

        // Hors fenêtre : 7 j + 1 h et 30 j + 1 h dans le passé (heure de Paris).
        // L'ancien code comparait à `datetime('now')` (UTC) → ces lignes étaient
        // comptées à tort.
        $this->insertClosed($formId, $this->paris('-7 days', -3600), $this->paris('-7 days', -3600), 'out-week@test.stats');
        $this->insertClosed($formId, $this->paris('-30 days', -3600), $this->paris('-30 days', -3600), 'out-month@test.stats');

        // Dans la fenêtre semaine/mois.
        $this->insertClosed($formId, $this->paris('-6 days'), $this->paris('-6 days'), 'in-week@test.stats');

        // Aujourd'hui (heure de Paris).
        $now = $this->paris('now');
        $this->insertClosed($formId, $now, $now, 'today@test.stats');

        $counts = $this->repo->getGlobalStatsCounts();

        self::assertSame(1, (int) $counts['today'], 'une soumission de maintenant doit compter dans today (date de Paris)');
        self::assertSame(2, (int) $counts['this_week'], 'seules les lignes à -6 j et maintenant sont dans this_week');
        // La ligne à -7 j - 1 h est hors semaine mais dans le mois ; celle à
        // -30 j - 1 h est hors mois : 3 lignes retenues (-7 j, -6 j, maintenant)
        // au lieu de 4 avec l'ancien seuil UTC.
        self::assertSame(3, (int) $counts['this_month'], 'seules les lignes à -7 j, -6 j et maintenant sont dans this_month');
    }

    public function testDailyCountsUsesParisReferenceWindow(): void
    {
        $formId = $this->insertForm('stats-tz-daily');

        // Hors fenêtre (7 j + 1 h) : exclue par la correction, comptée avec l'ancien
        // seuil UTC.
        $this->insertClosed($formId, $this->paris('-7 days', -3600), $this->paris('-7 days', -3600), 'daily-out@test.stats');
        $this->insertClosed($formId, $this->paris('-2 days'), $this->paris('-2 days'), 'daily-in@test.stats');

        $daily = $this->repo->getDailyCounts(7);
        $total = 0;
        foreach ($daily as $day) {
            $total += (int) $day['cnt'];
        }
        self::assertSame(1, $total, 'seule la soumission à -2 j doit rester dans la fenêtre de 7 jours');
    }

    // ── Fixtures ────────────────────────────────────────────────────

    /**
     * Horodatage en heure locale de Paris (comme FormSubmissionHandler via PHP
     * date()), décalé d'une durée relative — ex. paris('-7 days', -3600).
     */
    private function paris(string $modifier, int $offsetSeconds = 0): string
    {
        $ts = strtotime($modifier);
        return date('Y-m-d H:i:s', ($ts !== false ? $ts : time()) + $offsetSeconds);
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
        $this->seq++;
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
