<?php
declare(strict_types=1);

/**
 * Webhook notifications & DB size — thin wrappers delegating to WebhookService.
 *
 * @package lib
 */

function send_webhook(string $event, array $data): void {
    \App\Core\App::webhook()->send($event, $data);
}

function get_db_size(): int {
    return \App\Core\App::webhook()->getDbSize();
}
