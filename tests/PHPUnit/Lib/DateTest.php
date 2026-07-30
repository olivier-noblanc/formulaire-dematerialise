<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class DateTest extends TestCase
{
    public function testParseDeadlineDateIso(): void
    {
        $ts = parse_deadline_date('2026-01-15');
        self::assertIsInt($ts);
        self::assertSame(2026, (int)date('Y', $ts));
        self::assertSame(1, (int)date('m', $ts));
        self::assertSame(15, (int)date('d', $ts));
    }

    public function testParseDeadlineDateFrench(): void
    {
        $ts = parse_deadline_date('15/01/2026');
        self::assertIsInt($ts);
        self::assertSame(2026, (int)date('Y', $ts));
        self::assertSame(1, (int)date('m', $ts));
        self::assertSame(15, (int)date('d', $ts));
    }

    public function testParseDeadlineDateInvalid(): void
    {
        self::assertNull(parse_deadline_date('not-a-date'));
        self::assertNull(parse_deadline_date(''));
        self::assertNull(parse_deadline_date('2026/01/15'));
    }

    public function testParseDeadlineDateWithSpaces(): void
    {
        $ts = parse_deadline_date('  2026-01-15  ');
        self::assertIsInt($ts);
    }

    public function testParseDateIso(): void
    {
        $dt = parse_date('2026-03-15');
        self::assertInstanceOf(\DateTimeImmutable::class, $dt);
        self::assertSame(15, (int)$dt->format('d'));
        self::assertSame(3, (int)$dt->format('m'));
        self::assertSame(2026, (int)$dt->format('Y'));
    }

    public function testParseDateFrench(): void
    {
        $dt = parse_date('15/03/2026');
        self::assertInstanceOf(\DateTimeImmutable::class, $dt);
        self::assertSame(15, (int)$dt->format('d'));
        self::assertSame(3, (int)$dt->format('m'));
    }

    public function testParseDateInvalid(): void
    {
        self::assertNull(parse_date('not-a-date'));
        self::assertNull(parse_date(''));
        self::assertNull(parse_date('2026-13-01'));
    }

    public function testParseDateUsesParisTimezone(): void
    {
        $dt = parse_date('2026-06-15');
        self::assertSame('Europe/Paris', $dt->getTimezone()->getName());
    }

    public function testCalculateDeadlineUrgencyOverdue(): void
    {
        $result = calculate_deadline_urgency('2020-01-01');
        self::assertSame('overdue', $result['urgency']);
        self::assertIsInt($result['days_left']);
        self::assertLessThan(0, $result['days_left']);
    }

    public function testCalculateDeadlineUrgencyOk(): void
    {
        $result = calculate_deadline_urgency('2099-12-31');
        self::assertSame('ok', $result['urgency']);
        self::assertGreaterThan(5, $result['days_left']);
    }

    public function testCalculateDeadlineUrgencyEmptyDate(): void
    {
        $result = calculate_deadline_urgency('');
        self::assertSame('', $result['urgency']);
        self::assertNull($result['days_left']);
    }

    public function testCalculateDeadlineUrgencyNonEnCoursStatus(): void
    {
        $result = calculate_deadline_urgency('2020-01-01', 'valide');
        self::assertSame('', $result['urgency']);
        self::assertNull($result['days_left']);
    }

    public function testCalculateDeadlineUrgencyInvalidDate(): void
    {
        $result = calculate_deadline_urgency('not-a-date');
        self::assertSame('', $result['urgency']);
        self::assertNull($result['days_left']);
    }

    public function testCalculateDeadlineUrgencyCriticalRange(): void
    {
        // A date 1 day from now should be "critical"
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $result = calculate_deadline_urgency($tomorrow);
        self::assertSame('critical', $result['urgency']);
        self::assertStringContainsString('font-weight:bold', $result['style']);
    }

    public function testCalculateDeadlineUrgencyWarningRange(): void
    {
        // A date 4 days from now should be "warning"
        $inFourDays = date('Y-m-d', strtotime('+4 days'));
        $result = calculate_deadline_urgency($inFourDays);
        self::assertSame('warning', $result['urgency']);
    }
}
