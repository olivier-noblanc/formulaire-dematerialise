<?php
/**
 * lib_date.php — Helpers de parsing et calcul de dates.
 *
 * Module Phase 1 du découpage progressif de helpers.php (S3-CTO).
 * Chargé automatiquement par helpers.php via require_once — aucune inclusion
 * manuelle nécessaire. Les fonctions restent disponibles globalement
 * (pas de namespace, pas de classe) — compatibilité ascendante totale.
 *
 * Fonctions exposées :
 *  - parse_deadline_date() : parse une date YYYY-MM-DD ou DD/MM/YYYY → timestamp Unix
 *  - parse_date()          : parse une date → DateTimeImmutable (Europe/Paris)
 *  - calculate_deadline_urgency() : calcule l'urgence d'une deadline (overdue/critical/warning/ok)
 *
 * Aucune dépendance externe — utilise uniquement des fonctions natives PHP
 * (preg_match, strtotime, DateTimeImmutable, DateTimeZone, time, trim).
 *
 * Plan 3 phases (CTO, REUNION1-CTO §4) :
 *  - Phase 1 (S3, cette version) : fonctions autonomes peu couplées.
 *  - Phase 2 (S4) : fonctions medium-coupling (workflow, mail, LDAP, RGPD).
 *  - Phase 3 (S5+) : fonctions à couplage fort (DB, cache, settings).
 */

// Parse une date au format YYYY-MM-DD ou DD/MM/YYYY et retourne le timestamp Unix (ou null)
function parse_deadline_date(string $dateStr): ?int {
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
 * Parse une date en format YYYY-MM-DD ou DD/MM/YYYY et retourne un DateTimeImmutable.
 * Version centralisée (A-08/D8) — utilisée par alert_check.php et les calculs de deadline.
 *
 * @param string $date_str Date à parser
 * @return DateTimeImmutable|null L'objet date ou null si invalide
 */
function parse_date(string $date_str): ?DateTimeImmutable {
    $date_str = trim($date_str);
    // Format YYYY-MM-DD (HTML date input)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
        try {
            return new DateTimeImmutable($date_str . ' 00:00:00', new DateTimeZone('Europe/Paris'));
        } catch (Exception $e) {
            return null;
        }
    }
    // Format DD/MM/YYYY
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date_str, $m)) {
        try {
            return new DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]} 00:00:00", new DateTimeZone('Europe/Paris'));
        } catch (Exception $e) {
            return null;
        }
    }
    return null;
}

// Calcule l'urgence d'une deadline : retourne ['days_left' => int, 'urgency' => 'overdue'|'critical'|'warning'|'ok'|'']
/**
 * @return array<string, mixed>
 */
function calculate_deadline_urgency(string $deadlineVal, string $status = 'en_cours'): array {
    $result = ['days_left' => null, 'urgency' => '', 'style' => ''];
    if (empty($deadlineVal) || $status !== 'en_cours') return $result;
    $ts = parse_deadline_date($deadlineVal);
    if ($ts === null) return $result;
    $days_left = (int)(($ts - time()) / 86400);
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
