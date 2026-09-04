<?php

declare(strict_types=1);

namespace App\Core;

use App\Enum\SubmissionStatus;
use App\Enum\UrgencyLevel;

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
            $ts = strtotime($dateStr);
            return $ts !== false ? $ts : null;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $dateStr, $m)) {
            $ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]}");
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    /**
     * Parse a date string and return a DateTimeImmutable (Europe/Paris).
     *
     * FIX-C (2026-09-03) : validation du calendrier réel via checkdate() —
     * DateTimeImmutable normalise silencieusement les débordements de jour
     * (ex. 30/02/2026 → 02/03/2026), ce qui ferait accepter une saisie
     * invalide comme une autre date. Formats acceptés : AAAA-MM-JJ ou
     * JJ/MM/AAAA ; toute autre valeur retourne null.
     */
    public static function parseDate(string $date_str): ?\DateTimeImmutable
    {
        $date_str = trim($date_str);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date_str, $m)) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            return new \DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00', new \DateTimeZone('Europe/Paris'));
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date_str, $m)) {
            if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return null;
            }
            return new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]} 00:00:00", new \DateTimeZone('Europe/Paris'));
        }
        return null;
    }

    /**
     * Nombre de jours CALENDARIOS entre $now (défaut : maintenant Europe/Paris)
     * et $deadline : 0 = jour même (J0), 1 = demain (J-1), -1 = hier (retard d'un jour).
     *
     * P0-2 (2026-09-03) : remplace les calculs en secondes
     * (floor((ts - time())/86400) et DateInterval '%a') qui tronquent en
     * périodes de 24h pleines — deadline demain 00:00 vue à 15:00 donnait 0
     * (J-0) au lieu de 1 (J-1), et le jour J donnait -1 (« en retard »).
     * Source unique partagée par alert_check.php et calculateDeadlineUrgency().
     */
    public static function calendarDaysUntil(\DateTimeImmutable $deadline, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $nowDay = $now->setTime(0, 0);
        $deadlineDay = $deadline->setTime(0, 0);
        return (int) $nowDay->diff($deadlineDay)->format('%r%a');
    }

    /**
     * Début du jour civil de Paris converti en UTC, au format SQL 'Y-m-d H:i:s'.
     *
     * S5 (2026-09-03) : sert de borne de dédoublonnage pour alert_check.php —
     * les alertes sont loggées avec sent_at en UTC (datetime('now')) mais
     * « aujourd'hui » doit être le jour civil de Paris, pas le jour UTC.
     * sent_at étant monotone, `sent_at >= borne` ⇔ « envoyé aujourd'hui à
     * Paris ». La conversion via DateTimeImmutable gère le DST (23h/25h)
     * contrairement à un offset fixe (+1h/+2h en dur).
     *
     * @param \DateTimeImmutable $now Instant courant (Europe/Paris attendu,
     *                                comme $now dans alert_check.php)
     */
    public static function parisDayStartUtc(\DateTimeImmutable $now): string
    {
        return $now->setTime(0, 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Calculate deadline urgency.
     * @return array{days_left: ?int, urgency: string, style: string}
     */
    public static function calculateDeadlineUrgency(string $deadlineVal, string $status = SubmissionStatus::EnCours->value): array
    {
        $result = ['days_left' => null, 'urgency' => '', 'style' => ''];
        if ($deadlineVal === '' || $deadlineVal === '0' || $status !== SubmissionStatus::EnCours->value) {
            return $result;
        }
        // P0-2 : jours calendaires (Europe/Paris) — plus de division par 86400.
        $deadline = self::parseDate($deadlineVal);
        if (!$deadline instanceof \DateTimeImmutable) {
            return $result;
        }
        $days_left = self::calendarDaysUntil($deadline);
        $result['days_left'] = $days_left;
        if ($days_left < 0) {
            $result['urgency'] = UrgencyLevel::Overdue->value;
            $result['style'] = 'deadline-overdue';
        } elseif ($days_left <= 2) {
            $result['urgency'] = UrgencyLevel::Critical->value;
            $result['style'] = 'deadline-critical';
        } elseif ($days_left <= 5) {
            $result['urgency'] = 'warning';
            $result['style'] = 'deadline-warning';
        } else {
            $result['urgency'] = 'ok';
            $result['style'] = '';
        }
        return $result;
    }
}
