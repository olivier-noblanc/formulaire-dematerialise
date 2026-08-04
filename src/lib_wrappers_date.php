<?php

declare(strict_types=1);

/**
 * Global date helpers.
 *
 * Delegates to App\Core\DateHelper.
 * Loaded by lib_wrappers.php (main loader).
 */

use App\Core\DateHelper;
use App\Enum\SubmissionStatus;

function parse_deadline_date(string $dateStr): ?int
{
    return DateHelper::parseDeadlineDate($dateStr);
}
function parse_date(string $date_str): ?DateTimeImmutable
{
    return DateHelper::parseDate($date_str);
}
/**
 * @return array{days_left: ?int, urgency: string, style: string}
 */
function calculate_deadline_urgency(string $deadlineVal, string $status = SubmissionStatus::EnCours->value): array
{
    return DateHelper::calculateDeadlineUrgency($deadlineVal, $status);
}
