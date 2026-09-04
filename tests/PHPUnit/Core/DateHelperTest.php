<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\DateHelper;

final class DateHelperTest extends TestCase
{
    // ── parseDeadlineDate ───────────────────────────────────────

    public function testParseDeadlineDateReturnsTimestampForValidDate(): void
    {
        $result = DateHelper::parseDeadlineDate('15/01/2026');
        self::assertIsInt($result);
        self::assertSame('2026-01-15', date('Y-m-d', $result));
    }

    public function testParseDeadlineDateReturnsTimestampForIsoDate(): void
    {
        $result = DateHelper::parseDeadlineDate('2026-01-15');
        self::assertIsInt($result);
        self::assertSame('2026-01-15', date('Y-m-d', $result));
    }

    public function testParseDeadlineDateReturnsNullForEmptyString(): void
    {
        self::assertNull(DateHelper::parseDeadlineDate(''));
    }

    public function testParseDeadlineDateReturnsNullForInvalidFormat(): void
    {
        self::assertNull(DateHelper::parseDeadlineDate('not-a-date'));
    }

    // ── calculateDeadlineUrgency ────────────────────────────────

    public function testDeadlineUrgencyReturnsNullForEmptyDeadline(): void
    {
        $result = DateHelper::calculateDeadlineUrgency('');
        self::assertNull($result['days_left']);
        self::assertSame('', $result['urgency']);
    }

    public function testDeadlineUrgencyReturnsNullForClosedStatus(): void
    {
        $result = DateHelper::calculateDeadlineUrgency('15/01/2026', 'valide');
        self::assertNull($result['days_left']);
    }

    public function testDeadlineUrgencyOverdueReturnsNegativeDays(): void
    {
        $twoDaysAgo = date('d/m/Y', strtotime('-2 days'));
        $result = DateHelper::calculateDeadlineUrgency($twoDaysAgo);
        self::assertLessThan(0, $result['days_left'], 'Overdue deadline should have negative days_left');
        self::assertSame('overdue', $result['urgency']);
    }

    public function testDeadlineUrgencySixHoursOverdueReturnsMinusOneNotZero(): void
    {
        // Core bug fix: deadline yesterday = -1 day (not 0)
        $yesterday = date('d/m/Y', strtotime('-1 day'));
        $result = DateHelper::calculateDeadlineUrgency($yesterday);
        self::assertLessThan(0, $result['days_left'], 'Yesterday deadline should be -1, not 0');
        self::assertSame('overdue', $result['urgency']);
    }

    public function testDeadlineUrgencyCriticalForTwoDaysLeft(): void
    {
        $inTwoDays = date('d/m/Y', strtotime('+2 days'));
        $result = DateHelper::calculateDeadlineUrgency($inTwoDays);
        self::assertSame('critical', $result['urgency']);
    }

    public function testDeadlineUrgencyOkForFiveDaysLeft(): void
    {
        $inSevenDays = date('d/m/Y', strtotime('+7 days'));
        $result = DateHelper::calculateDeadlineUrgency($inSevenDays);
        self::assertSame('ok', $result['urgency']);
    }

    // ── calendarDaysUntil — P0-2 : jours calendaires J-1/J0/J+1 ──

    public function testCalendarDaysUntilSameDayIsZero(): void
    {
        // J0 : deadline aujourd'hui, peu importe l'heure → 0
        $now = new \DateTimeImmutable('2026-09-03 15:00:00', new \DateTimeZone('Europe/Paris'));
        $deadline = new \DateTimeImmutable('2026-09-03 00:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame(0, DateHelper::calendarDaysUntil($deadline, $now));
    }

    public function testCalendarDaysUntilTomorrowIsOneEvenLateInDay(): void
    {
        // Bug P0-2 : les anciens calculs (floor(Δt/86400) et DateInterval %a)
        // tronquaient en périodes de 24h pleines → deadline demain 00:00 vue
        // à 23:30 donnait 0 (J-0) au lieu de 1 (J-1).
        $now = new \DateTimeImmutable('2026-09-03 23:30:00', new \DateTimeZone('Europe/Paris'));
        $deadline = new \DateTimeImmutable('2026-09-04 00:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame(1, DateHelper::calendarDaysUntil($deadline, $now));
    }

    public function testCalendarDaysUntilInTwoDaysIsTwo(): void
    {
        // Deadline dans 2 jours vue à 15:00 : 33h pleines → %a donnait 1 au lieu de 2.
        $now = new \DateTimeImmutable('2026-09-03 15:00:00', new \DateTimeZone('Europe/Paris'));
        $deadline = new \DateTimeImmutable('2026-09-05 00:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame(2, DateHelper::calendarDaysUntil($deadline, $now));
    }

    public function testCalendarDaysUntilYesterdayIsMinusOne(): void
    {
        $now = new \DateTimeImmutable('2026-09-03 15:00:00', new \DateTimeZone('Europe/Paris'));
        $deadline = new \DateTimeImmutable('2026-09-02 00:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame(-1, DateHelper::calendarDaysUntil($deadline, $now));
    }

    public function testCalendarDaysUntilAcrossDstSpringForwardIsCalendarBased(): void
    {
        // Nuit du 28 au 29 mars 2026 : passage à l'heure d'été, journée de 23h.
        // Un calcul en secondes /86400 donnerait 0 ; calendaire = 1.
        $now = new \DateTimeImmutable('2026-03-28 12:00:00', new \DateTimeZone('Europe/Paris'));
        $deadline = new \DateTimeImmutable('2026-03-29 00:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame(1, DateHelper::calendarDaysUntil($deadline, $now));
    }

    public function testCalendarDaysUntilNullNowUsesTodayParis(): void
    {
        $tomorrow = new \DateTimeImmutable('tomorrow midnight', new \DateTimeZone('Europe/Paris'));
        self::assertSame(1, DateHelper::calendarDaysUntil($tomorrow));
    }

    // ── calculateDeadlineUrgency — jours calendaires (P0-2) ──

    public function testDeadlineUrgencyTodayIsZeroCriticalNotOverdue(): void
    {
        // Bug P0-2 : date limite = aujourd'hui → J0 (critique), pas "en retard".
        // L'ancienne formule floor((ts - time())/86400) donnait -1 dès le matin du jour J.
        $today = date('d/m/Y');
        $result = DateHelper::calculateDeadlineUrgency($today);
        self::assertSame(0, $result['days_left'], "Deadline aujourd'hui = J0, pas -1");
        self::assertSame('critical', $result['urgency']);
    }

    public function testDeadlineUrgencyTomorrowIsOne(): void
    {
        // Bug P0-2 : deadline demain → days_left = 1 (J-1), pas 0.
        $tomorrow = date('d/m/Y', strtotime('+1 day'));
        $result = DateHelper::calculateDeadlineUrgency($tomorrow);
        self::assertSame(1, $result['days_left'], 'Deadline demain = J-1, days_left doit valoir 1');
        self::assertSame('critical', $result['urgency']);
    }

    // ── parisDayStartUtc — S5 : dédoublonnage alertes sur le jour Paris ──

    public function testParisDayStartUtcWinter(): void
    {
        // Hiver (UTC+1) : minuit Paris 05/01 = 04/01 23:00 UTC.
        $now = new \DateTimeImmutable('2026-01-05 03:30:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame('2026-01-04 23:00:00', DateHelper::parisDayStartUtc($now));
    }

    public function testParisDayStartUtcSummer(): void
    {
        // Été (UTC+2) : minuit Paris 05/07 = 04/07 22:00 UTC.
        $now = new \DateTimeImmutable('2026-07-05 01:30:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame('2026-07-04 22:00:00', DateHelper::parisDayStartUtc($now));
    }

    public function testParisDayStartUtcSpringForwardDay(): void
    {
        // 29/03/2026 : jour du passage à l'heure d'été — minuit Paris
        // est encore en UTC+1 → 28/03 23:00 UTC.
        $now = new \DateTimeImmutable('2026-03-29 12:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame('2026-03-28 23:00:00', DateHelper::parisDayStartUtc($now));
    }

    public function testParisDayStartUtcFallBackDay(): void
    {
        // 25/10/2026 : jour du retour à l'heure d'hiver — minuit Paris
        // est encore en UTC+2 → 24/10 22:00 UTC.
        $now = new \DateTimeImmutable('2026-10-25 12:00:00', new \DateTimeZone('Europe/Paris'));
        self::assertSame('2026-10-24 22:00:00', DateHelper::parisDayStartUtc($now));
    }
}
