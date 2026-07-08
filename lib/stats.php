<?php
declare(strict_types=1);

/**
 * Statistics & full-text search — thin wrappers delegating to StatsService.
 *
 * @package lib
 */

function search_submissions(string $query, array $filters = []): array {
    return \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->searchSubmissions($query, $filters);
}

function get_stats_by_period(string $period = 'month', int $limit = 12): array {
    return \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod($period, $limit);
}

function get_global_stats(): array {
    return \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
}
