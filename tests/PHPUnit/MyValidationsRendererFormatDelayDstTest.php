<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Render\MyValidationsRenderer;

/**
 * formatDelay() (MyValidationsRenderer) : `done_at` et `sent_at` sont stockés en
 * UTC. Les lire via `strtotime()` sans fuseau explicite les interprète dans le
 * fuseau par défaut (Europe/Paris en prod). Entre deux instants séparés par un
 * changement d'heure, la différence calculée est fausse d'une heure : le décalage
 * ne « s'annule » plus dans la soustraction.
 *
 * Reproduit avec le passage CET (UTC+1) → CEST (UTC+2) du 29/03/2026.
 */
final class MyValidationsRendererFormatDelayDstTest extends TestCase
{
    private function formatDelay(string $doneAt, string $sentAt): string
    {
        // Méthode privée : testée via réflexion (pattern utilisé ailleurs dans la suite).
        $method = new \ReflectionMethod(MyValidationsRenderer::class, 'formatDelay');
        /** @var string $result */
        $result = $method->invoke(null, $doneAt, $sentAt);
        return $result;
    }

    public function testDelayAcrossSpringForwardCountsRealUtcDuration(): void
    {
        $previousTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
        try {
            // sent_at 01:00 UTC, done_at 04:00 UTC → 3 h réelles. Interprétés à
            // tort comme heure locale de Paris, la différence vaudrait 2 h
            // (01:00 CET = 00:00 UTC ; 04:00 CEST = 02:00 UTC).
            $html = $this->formatDelay('2026-03-29 04:00:00', '2026-03-29 01:00:00');
        } finally {
            date_default_timezone_set($previousTz);
        }

        self::assertStringContainsString(
            '3 h',
            $html,
            'formatDelay doit compter la durée réelle entre deux instants UTC (DST-safe).'
        );
        self::assertStringNotContainsString('2 h', $html, 'La lecture naïve en heure locale Paris donnerait 2 h.');
    }

    public function testDelayWithMissingTimestampReturnsPlaceholder(): void
    {
        self::assertSame('?', $this->formatDelay('', '2026-01-01 00:00:00'));
    }
}
