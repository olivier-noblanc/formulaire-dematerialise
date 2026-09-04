<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * S5/S6 (2026-09-03) — alert_check.php : dédoublonnage sur le jour civil de Paris.
 *
 * L'ancienne requête `DATE(sent_at) = DATE(?…)` comparait le jour UTC de
 * sent_at au jour UTC courant : une alerte envoyée à 23:30 Paris (21:30 UTC)
 * était encore considérée « déjà envoyée aujourd'hui » à 00:30 Paris le
 * lendemain (22:30 UTC, même jour UTC) — fenêtre de 1-2h à chaque changement
 * de jour où aucune relance d'alerte ne pouvait partir.
 *
 * Le correctif borne le dédoublonnage sur le début du jour civil de Paris
 * converti en UTC (DateHelper::parisDayStartUtc, DST-safe) : sent_at étant
 * stocké en UTC et monotone, `sent_at >= borne` ⇔ « envoyé aujourd'hui à Paris ».
 *
 * Fichier : tests/PHPUnit/AlertCheckParisDayDedupTest.php
 */
final class AlertCheckParisDayDedupTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/alert_check.php';
        self::assertFileExists($path);
        $this->source = (string) file_get_contents($path);
    }

    public function testDedupUsesParisDayStartBoundary(): void
    {
        self::assertStringContainsString(
            'DateHelper::parisDayStartUtc(',
            $this->source,
            'La borne de dédoublonnage doit venir de DateHelper::parisDayStartUtc() (source unique, DST-safe)'
        );
        self::assertStringContainsString(
            'sent_at >= ?',
            $this->source,
            'Le dédoublonnage doit borner sur sent_at >= début du jour Paris (en UTC)'
        );
    }

    public function testNoUtcDayEqualityComparison(): void
    {
        self::assertStringNotContainsString(
            'DATE(sent_at) = DATE(',
            $this->source,
            'La comparaison sur le jour UTC doit disparaître (dédoublonnage par jour civil de Paris)'
        );
    }

    public function testMisleadingUtcCommentRemoved(): void
    {
        // S6 : le commentaire « Utiliser UTC pour la comparaison » justifiait
        // le bug — il est remplacé par l'explication jour civil de Paris.
        self::assertStringNotContainsString(
            'Utiliser UTC pour la comparaison',
            $this->source,
            'Le commentaire obsolète justifiant la comparaison UTC doit être supprimé'
        );
    }

    /**
     * Sémantique SQL du dédoublonnage sur une vraie base SQLite : la borne
     * Paris-minuit-UTC sépare correctement « même jour Paris » / « jour Paris
     * précédent », y compris quand le jour UTC est identique pour les deux.
     */
    public function testDedupBoundarySeparatesParisDaysOnSqlite(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 's5dedup_');
        self::assertNotFalse($dbPath);
        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE alert_log (id TEXT PRIMARY KEY, rule_id TEXT, submission_id TEXT, sent_at DATETIME, message TEXT)');

            // Été 2026 (UTC+2) : borne du jour Paris courant = 04/07 22:00 UTC.
            $now = new \DateTimeImmutable('2026-07-05 01:30:00', new \DateTimeZone('Europe/Paris'));
            $boundary = \App\Core\DateHelper::parisDayStartUtc($now);
            self::assertSame('2026-07-04 22:00:00', $boundary);

            // A : envoyée à 23:30 Paris le 04/07 = 21:30 UTC le 04/07 → veille Paris.
            $pdo->exec("INSERT INTO alert_log (id, rule_id, submission_id, sent_at, message) VALUES ('a', 'r1', 's1', '2026-07-04 21:30:00', 'veille')");
            // B : envoyée à 00:30 Paris le 05/07 = 22:30 UTC le 04/07 → même jour Paris.
            $pdo->exec("INSERT INTO alert_log (id, rule_id, submission_id, sent_at, message) VALUES ('b', 'r1', 's1', '2026-07-04 22:30:00', 'courant')");

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM alert_log WHERE rule_id = ? AND submission_id = ? AND sent_at >= ?');
            $stmt->execute(['r1', 's1', $boundary]);
            $dedupCount = (int) $stmt->fetchColumn();
            $stmt = null;

            // Seule l'alerte du jour Paris courant bloque : l'alerte de la veille
            // (même jour UTC !) ne doit pas empêcher une nouvelle alerte.
            self::assertSame(1, $dedupCount, 'Seule l\'alerte envoyée le même jour civil de Paris doit déclencher le dédoublonnage');

            $stmt = $pdo->prepare('SELECT id FROM alert_log WHERE rule_id = ? AND submission_id = ? AND sent_at >= ?');
            $stmt->execute(['r1', 's1', $boundary]);
            $blockingId = $stmt->fetchColumn();
            $stmt = null;
            self::assertSame('b', $blockingId, 'L\'alerte bloquante doit être celle du jour Paris courant, pas celle de la veille');
        } finally {
            if (is_file($dbPath)) {
                @unlink($dbPath);
            }
        }
    }
}
