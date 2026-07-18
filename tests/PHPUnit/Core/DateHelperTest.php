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
        $this->assertIsInt($result);
        $this->assertSame('2026-01-15', date('Y-m-d', $result));
    }

    public function testParseDeadlineDateReturnsTimestampForIsoDate(): void
    {
        $result = DateHelper::parseDeadlineDate('2026-01-15');
        $this->assertIsInt($result);
        $this->assertSame('2026-01-15', date('Y-m-d', $result));
    }

    public function testParseDeadlineDateReturnsNullForEmptyString(): void
    {
        $this->assertNull(DateHelper::parseDeadlineDate(''));
    }

    public function testParseDeadlineDateReturnsNullForInvalidFormat(): void
    {
        $this->assertNull(DateHelper::parseDeadlineDate('not-a-date'));
    }

    // ── calculateDeadlineUrgency ────────────────────────────────

    public function testDeadlineUrgencyReturnsNullForEmptyDeadline(): void
    {
        $result = DateHelper::calculateDeadlineUrgency('');
        $this->assertNull($result['days_left']);
        $this->assertSame('', $result['urgency']);
    }

    public function testDeadlineUrgencyReturnsNullForClosedStatus(): void
    {
        $result = DateHelper::calculateDeadlineUrgency('15/01/2026', 'valide');
        $this->assertNull($result['days_left']);
    }

    public function testDeadlineUrgencyOverdueReturnsNegativeDays(): void
    {
        $twoDaysAgo = date('d/m/Y', strtotime('-2 days'));
        $result = DateHelper::calculateDeadlineUrgency($twoDaysAgo);
        $this->assertLessThan(0, $result['days_left'], 'Overdue deadline should have negative days_left');
        $this->assertSame('overdue', $result['urgency']);
    }

    public function testDeadlineUrgencySixHoursOverdueReturnsMinusOneNotZero(): void
    {
        // Core bug fix: deadline yesterday = -1 day (not 0)
        $yesterday = date('d/m/Y', strtotime('-1 day'));
        $result = DateHelper::calculateDeadlineUrgency($yesterday);
        $this->assertLessThan(0, $result['days_left'], 'Yesterday deadline should be -1, not 0');
        $this->assertSame('overdue', $result['urgency']);
    }

    public function testDeadlineUrgencyCriticalForTwoDaysLeft(): void
    {
        $inTwoDays = date('d/m/Y', strtotime('+2 days'));
        $result = DateHelper::calculateDeadlineUrgency($inTwoDays);
        $this->assertSame('critical', $result['urgency']);
    }

    public function testDeadlineUrgencyOkForFiveDaysLeft(): void
    {
        $inSevenDays = date('d/m/Y', strtotime('+7 days'));
        $result = DateHelper::calculateDeadlineUrgency($inSevenDays);
        $this->assertSame('ok', $result['urgency']);
    }
}
