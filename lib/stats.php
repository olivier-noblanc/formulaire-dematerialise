<?php
declare(strict_types=1);

/**
 * Statistics & full-text search.
 *
 * search_submissions() — recherche plein texte dans les soumissions
 * get_stats_by_period() — statistiques par période (semaine/mois/année)
 * get_global_stats() — statistiques globales pour le dashboard
 *
 * @package lib
 */

use App\Stats\StatsService;
use App\Core\Database;

// ── FULL-TEXT SEARCH ────────────────────────────────────────

/**
 * Recherche plein texte dans les soumissions
 * Cherche dans : submitted_by, data JSON, form_label
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function search_submissions(string $query, array $filters = []): array {
    // Sécurité (S-16) : limiter le nombre de recherches par IP
    if (!rate_limit_check('search', 30, 60)) {
        return [];
    }
    $service = new StatsService(new Database());
    return $service->searchSubmissions($query, $filters);
}

// ── STATISTICS ──────────────────────────────────────────────

/**
 * Statistiques par période
 * @return array<string, mixed>
 */
function get_stats_by_period(string $period = 'month', int $limit = 12): array {
    $service = new StatsService(new Database());
    return $service->getStatsByPeriod($period, $limit);
}

/**
 * Statistiques globales pour le dashboard
 * @return array<string, mixed>
 */
function get_global_stats(): array {
    $service = new StatsService(new Database());
    return $service->getGlobalStats();
}
