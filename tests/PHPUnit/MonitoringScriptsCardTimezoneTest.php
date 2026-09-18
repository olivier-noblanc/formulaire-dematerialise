<?php
declare(strict_types=1);

namespace App\Tests;

use App\Render\MonitoringRenderer;
use PHPUnit\Framework\TestCase;

/**
 * P2-E — affichage des horodatages de cron (remind.php / alert_check.php).
 *
 * `settings.last_remind_run` et `settings.last_alert_check` sont désormais
 * stockés en UTC. La carte « Scripts automatisés » doit :
 *  - parser la valeur en UTC pour calculer l'âge (fraîcheur < 24 h) ;
 *  - afficher la date reconvertie en Europe/Paris (formatDateTimeFr).
 *
 * Fichier : tests/PHPUnit/MonitoringScriptsCardTimezoneTest.php
 */
final class MonitoringScriptsCardTimezoneTest extends TestCase
{
    public function testScriptsCardDisplaysUtcTimestampsInParis(): void
    {
        // Hiver (UTC+1) : 10:00 UTC → 11:00 Paris ; été (UTC+2) : 10:00 → 12:00.
        $html = MonitoringRenderer::scriptsCard('2026-01-15 10:00:00', '2026-07-01 10:00:00');

        self::assertStringContainsString('15/01/2026 à 11:00', $html);
        self::assertStringContainsString('01/07/2026 à 12:00', $html);
    }

    public function testScriptsCardFreshRunIsActive(): void
    {
        $fresh = gmdate('Y-m-d H:i:s');

        $html = MonitoringRenderer::scriptsCard($fresh, $fresh);

        self::assertStringContainsString('Actif', $html);
        self::assertStringNotContainsString('plus de 24h', $html);
    }

    public function testScriptsCardStaleRunWarns(): void
    {
        // 3 jours dans le passé (UTC) → statut « plus de 24h ».
        $stale = gmdate('Y-m-d H:i:s', time() - 3 * 86400);

        $html = MonitoringRenderer::scriptsCard($stale, $stale);

        self::assertStringContainsString('plus de 24h', $html);
    }
}