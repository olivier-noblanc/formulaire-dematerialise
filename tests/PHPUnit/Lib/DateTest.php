<?php
declare(strict_types=1);

namespace App\Tests\Lib;

use PHPUnit\Framework\TestCase;

final class DateTest extends TestCase
{
    public function testParseDeadlineDateIso(): void
    {
        $ts = parse_deadline_date('2026-01-15');
        $this->assertIsInt($ts);
        $this->assertSame(2026, (int)date('Y', $ts));
        $this->assertSame(1, (int)date('m', $ts));
        $this->assertSame(15, (int)date('d', $ts));
    }

    public function testParseDeadlineDateFrench(): void
    {
        $ts = parse_deadline_date('15/01/2026');
        $this->assertIsInt($ts);
        $this->assertSame(2026, (int)date('Y', $ts));
        $this->assertSame(1, (int)date('m', $ts));
        $this->assertSame(15, (int)date('d', $ts));
    }

    public function testParseDeadlineDateInvalid(): void
    {
        $this->assertNull(parse_deadline_date('not-a-date'));
        $this->assertNull(parse_deadline_date(''));
        $this->assertNull(parse_deadline_date('2026/01/15'));
    }

    public function testParseDeadlineDateWithSpaces(): void
    {
        $ts = parse_deadline_date('  2026-01-15  ');
        $this->assertIsInt($ts);
    }

    public function testParseDateIso(): void
    {
        $dt = parse_date('2026-03-15');
        $this->assertInstanceOf(\DateTimeImmutable::class, $dt);
        $this->assertSame(15, (int)$dt->format('d'));
        $this->assertSame(3, (int)$dt->format('m'));
        $this->assertSame(2026, (int)$dt->format('Y'));
    }

    public function testParseDateFrench(): void
    {
        $dt = parse_date('15/03/2026');
        $this->assertInstanceOf(\DateTimeImmutable::class, $dt);
        $this->assertSame(15, (int)$dt->format('d'));
        $this->assertSame(3, (int)$dt->format('m'));
    }

    public function testParseDateInvalid(): void
    {
        $this->assertNull(parse_date('not-a-date'));
        $this->assertNull(parse_date(''));
        $this->assertNull(parse_date('2026-13-01'));
    }

    public function testParseDateUsesParisTimezone(): void
    {
        $dt = parse_date('2026-06-15');
        $this->assertSame('Europe/Paris', $dt->getTimezone()->getName());
    }

    public function testCalculateDeadlineUrgencyOverdue(): void
    {
        $result = calculate_deadline_urgency('2020-01-01');
        $this->assertSame('overdue', $result['urgency']);
        $this->assertIsInt($result['days_left']);
        $this->assertLessThan(0, $result['days_left']);
    }

    public function testCalculateDeadlineUrgencyOk(): void
    {
        $result = calculate_deadline_urgency('2099-12-31');
        $this->assertSame('ok', $result['urgency']);
        $this->assertGreaterThan(5, $result['days_left']);
    }

    public function testCalculateDeadlineUrgencyEmptyDate(): void
    {
        $result = calculate_deadline_urgency('');
        $this->assertSame('', $result['urgency']);
        $this->assertNull($result['days_left']);
    }

    public function testCalculateDeadlineUrgencyNonEnCoursStatus(): void
    {
        $result = calculate_deadline_urgency('2020-01-01', 'valide');
        $this->assertSame('', $result['urgency']);
        $this->assertNull($result['days_left']);
    }

    public function testCalculateDeadlineUrgencyInvalidDate(): void
    {
        $result = calculate_deadline_urgency('not-a-date');
        $this->assertSame('', $result['urgency']);
        $this->assertNull($result['days_left']);
    }

    public function testCalculateDeadlineUrgencyCriticalRange(): void
    {
        // A date 1 day from now should be "critical"
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $result = calculate_deadline_urgency($tomorrow);
        $this->assertSame('critical', $result['urgency']);
        $this->assertStringContainsString('font-weight:bold', $result['style']);
    }

    public function testCalculateDeadlineUrgencyWarningRange(): void
    {
        // A date 4 days from now should be "warning"
        $inFourDays = date('Y-m-d', strtotime('+4 days'));
        $result = calculate_deadline_urgency($inFourDays);
        $this->assertSame('warning', $result['urgency']);
    }
}
