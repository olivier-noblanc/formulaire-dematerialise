<?php
declare(strict_types=1);

/**
 * Audit & security logging — thin wrappers delegating to App\Audit\AuditLogService.
 *
 * app_log() — journal des actions admin (table audit_log)
 * security_log() — journal des événements de sécurité (table security_log)
 * get_audit_logs() — récupération filtrée des logs
 *
 * @package lib
 */

function app_log(string $action, string $target = '', string $detail = '', string $actor = ''): void {
    \App\Core\App::audit()->log($action, $target, $detail, $actor);
}

function security_log(string $event, string $detail = '', string $actor = ''): void {
    \App\Core\App::audit()->securityLog($event, $detail, $actor);
}

/** @return array<string, mixed> */
function get_audit_logs(int $limit = 100, string $action_filter = ''): array {
    return \App\Core\App::audit()->getLogs($limit, $action_filter);
}
