<?php
declare(strict_types=1);

/**
 * Lazy cron — deferred task execution + POST handler hook.
 *
 * @package lib
 */

function run_lazy_cron(PDO $pdo): void {
    \App\Core\App::cron()->runLazyCron();
}

/**
 * Parse une datetime SQLite (format 'Y-m-d H:i:s') en timestamp Unix.
 * Contourne le bug PHP 8.4 où strtotime() retourne DateTimeImmutable.
 */
function parse_db_datetime(string $datetime): ?int {
    return \App\Cron\CronService::parseDbDatetime($datetime);
}

function handle_post(): ?string {
    return \App\Core\App::cron()->handlePost();
}
