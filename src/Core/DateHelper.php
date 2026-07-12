<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Date parsing and deadline urgency helpers.
 */
final class DateHelper
{
    /**
     * Parse a date string (YYYY-MM-DD or DD/MM/YYYY) and return Unix timestamp.
     */
    public static function parseDeadlineDate(string $dateStr): ?int
    {
        $dateStr = trim($dateStr);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return strtotime($dateStr) ?: null;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $dateStr, $m)) {
            return strtotime("{$m[3]}-{$m[2]}-{$m[1]}") ?: null;
        }
        return null;
    }

    /**
     * Parse a date string and return a DateTimeImmutable (Europe/Paris).
     */
    public static function parseDate(string $date_str): ?\DateTimeImmutable
    {
        $date_str = trim($date_str);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            try {
                return new \DateTimeImmutable($date_str . ' 00:00:00', new \DateTimeZone('Europe/Paris'));
            } catch (\Exception) {
                return null;
            }
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date_str, $m)) {
            try {
                return new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]} 00:00:00", new \DateTimeZone('Europe/Paris'));
            } catch (\Exception) {
                return null;
            }
        }
        return null;
    }

    /**
     * Calculate deadline urgency.
     * @return array{days_left: ?int, urgency: string, style: string}
     */
    public static function calculateDeadlineUrgency(string $deadlineVal, string $status = 'en_cours'): array
    {
        $result = ['days_left' => null, 'urgency' => '', 'style' => ''];
        if ($deadlineVal === '' || $deadlineVal === '0' || $status !== 'en_cours') {
            return $result;
        }
        $ts = self::parseDeadlineDate($deadlineVal);
        if ($ts === null) {
            return $result;
        }
        $days_left = (int) (($ts - time()) / 86400);
        $result['days_left'] = $days_left;
        if ($days_left < 0) {
            $result['urgency'] = 'overdue';
            $result['style'] = 'color:#c0392b;font-weight:bold;';
        } elseif ($days_left <= 2) {
            $result['urgency'] = 'critical';
            $result['style'] = 'color:#c0392b;font-weight:bold;';
        } elseif ($days_left <= 5) {
            $result['urgency'] = 'warning';
            $result['style'] = 'color:#b45309;font-weight:bold;';
        } else {
            $result['urgency'] = 'ok';
            $result['style'] = '';
        }
        return $result;
    }
}
