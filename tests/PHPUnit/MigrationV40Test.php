<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Migration v40 — normalisation des horodatages Paris → UTC.
 *
 * Vérifie sur une base SQLite fichier temporaire :
 *  - chemin nominal été/hiver : `submissions.submitted_at` et les réglages
 *    `last_alert_check`/`last_remind_run` écrits en heure Paris sont convertis
 *    en UTC avec le bon décalage DST, version 40 marquée ;
 *  - idempotence : un second appel ne reconvertit pas les valeurs ;
 *  - self-healing : version déjà marquée → aucune conversion rejouée ;
 *  - valeurs invalides : laissées inchangées sans faire échouer la migration ;
 *  - garde de version : rien à faire si `$current_version >= 40`.
 *
 * Fichier : tests/PHPUnit/MigrationV40Test.php
 */
final class MigrationV40Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'v40test_');
        self::assertNotFalse($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    /**
     * Schéma pré-v40 : submissions + settings dans leur forme historique.
     */
    private function createBaseSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (39, datetime('now'))");
        $pdo->exec("CREATE TABLE submissions (
            id TEXT PRIMARY KEY,
            submitted_at DATETIME,
            closed_at DATETIME
        )");
        $pdo->exec("CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        // Été (UTC+2) : 2026-07-01 10:00 Paris = 08:00 UTC.
        $pdo->exec("INSERT INTO submissions (id, submitted_at) VALUES ('sub-summer', '2026-07-01 10:00:00')");
        // Hiver (UTC+1) : 2026-01-15 10:00 Paris = 09:00 UTC.
        $pdo->exec("INSERT INTO submissions (id, submitted_at) VALUES ('sub-winter', '2026-01-15 10:00:00')");
        // Valeur invalide : ne doit pas être modifiée.
        $pdo->exec("INSERT INTO submissions (id, submitted_at) VALUES ('sub-invalid', 'not-a-date')");

        $pdo->exec("INSERT INTO settings (key, value) VALUES ('last_alert_check', '2026-07-01 10:00:00')");
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('last_remind_run', '2026-01-15 10:00:00')");
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('unrelated_setting', '2026-07-01 10:00:00')");
    }

    private function openPdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function scalar(PDO $pdo, string $sql, string $param): string
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$param]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : '';
    }

    public function testHappyPathConvertsParisToUtcWithDst(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v40.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v40($pdo, 39);

        self::assertSame(40, $result);

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 40');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked, 'la version 40 doit être marquée');

        // Été (UTC+2) : 10:00 Paris → 08:00 UTC.
        self::assertSame('2026-07-01 08:00:00', $this->scalar($pdo, 'SELECT submitted_at FROM submissions WHERE id = ?', 'sub-summer'));
        // Hiver (UTC+1) : 10:00 Paris → 09:00 UTC.
        self::assertSame('2026-01-15 09:00:00', $this->scalar($pdo, 'SELECT submitted_at FROM submissions WHERE id = ?', 'sub-winter'));

        // Réglages convertis.
        self::assertSame('2026-07-01 08:00:00', $this->scalar($pdo, 'SELECT value FROM settings WHERE key = ?', 'last_alert_check'));
        self::assertSame('2026-01-15 09:00:00', $this->scalar($pdo, 'SELECT value FROM settings WHERE key = ?', 'last_remind_run'));

        // Réglage sans rapport : non touché.
        self::assertSame('2026-07-01 10:00:00', $this->scalar($pdo, 'SELECT value FROM settings WHERE key = ?', 'unrelated_setting'));
    }

    public function testInvalidValuesAreLeftUnchanged(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v40.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v40($pdo, 39);

        self::assertSame(40, $result, 'une valeur invalide ne doit pas faire échouer la migration');
        self::assertSame('not-a-date', $this->scalar($pdo, 'SELECT submitted_at FROM submissions WHERE id = ?', 'sub-invalid'));
    }

    public function testSecondCallIsIdempotent(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v40.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);
        apply_migration_v40($pdo, 39);

        $second = apply_migration_v40($pdo, 40);
        self::assertSame(40, $second);

        // Aucune double conversion (une seconde conversion donnerait 06:00).
        self::assertSame('2026-07-01 08:00:00', $this->scalar($pdo, 'SELECT submitted_at FROM submissions WHERE id = ?', 'sub-summer'));

        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 40');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(1, $marked, 'la version 40 ne doit être marquée qu\'une fois');
    }

    public function testSelfHealingWhenVersionAlreadyMarked(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v40.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);
        // Panne simulée : version marquée sans conversion effectuée.
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (40, datetime('now'))");

        $result = apply_migration_v40($pdo, 39);

        self::assertSame(40, $result);
        self::assertSame(
            '2026-07-01 10:00:00',
            $this->scalar($pdo, 'SELECT submitted_at FROM submissions WHERE id = ?', 'sub-summer'),
            'version déjà marquée → aucune conversion rejouée'
        );
    }

    public function testDoesNotRunWhenCurrentVersionAlreadyAt40(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/migrations/v40.php';
        $pdo = $this->openPdo();
        $this->createBaseSchema($pdo);

        $result = apply_migration_v40($pdo, 40);

        self::assertSame(40, $result);
        self::assertSame(
            '2026-07-01 10:00:00',
            $this->scalar($pdo, 'SELECT submitted_at FROM submissions WHERE id = ?', 'sub-summer'),
            'rien à faire si la version courante est déjà ≥ 40'
        );
        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 40');
        $marked = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        $stmt = null;
        self::assertSame(0, $marked);
    }
}