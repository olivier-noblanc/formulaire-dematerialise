<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P0-2 (2026-09-03) — alert_check.php : jours calendaires J-1/J0/J+1.
 *
 * L'ancien calcul `(int)$now->diff($deadline)->format('%r%a')` comptait des
 * périodes de 24h pleines : une deadline demain 00:00 vue à 15:00 donnait
 * « J-0 avant la date cible » (au lieu de J-1), et le jour J lui-même
 * déclenchait les branches « EN RETARD de 0 jours » / « DATE DÉPASSÉE »
 * (condition `<= 0`).
 *
 * Le calcul est délégué à DateHelper::calendarDaysUntil() (source unique de
 * vérité, testée unitairement dans DateHelperTest) et les branches retard
 * sont strictement négatives (< 0).
 *
 * Fichier : tests/PHPUnit/AlertCheckCalendarDaysTest.php
 */
final class AlertCheckCalendarDaysTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/alert_check.php';
        self::assertFileExists($path);
        $this->source = (string) file_get_contents($path);
    }

    public function testUsesCalendarDaysUntilHelper(): void
    {
        self::assertStringContainsString(
            'DateHelper::calendarDaysUntil(',
            $this->source,
            'alert_check.php doit déléguer le calcul J-1/J0/J+1 à DateHelper::calendarDaysUntil()'
        );
    }

    public function testNoFull24hDayDiffCalculation(): void
    {
        self::assertStringNotContainsString(
            'diff($deadline)',
            $this->source,
            'Le calcul %a (périodes de 24h pleines) doit disparaître au profit des jours calendaires'
        );
    }

    public function testOverdueBranchIsStrictlyNegative(): void
    {
        // J0 (deadline aujourd'hui) ne doit pas être traité comme « EN RETARD »
        // ni « DATE DÉPASSÉE » — la branche retard doit être strictement < 0.
        self::assertDoesNotMatchRegularExpression(
            '#days_remaining\s*<=\s*0\s*\?#',
            $this->source,
            'La branche retard doit être strictement < 0 (J0 = deadline du jour, pas un retard)'
        );
    }
}
