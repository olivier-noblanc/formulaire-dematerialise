<?php
declare(strict_types=1);

/**
 * Webhook notifications & DB size.
 *
 * @package lib
 */

// ── WEBHOOK NOTIFICATIONS ───────────────────────────────────

/**
 * Envoie une notification webhook si configuré
 * @param array<string, mixed> $data
 */
function send_webhook(string $event, array $data): void {
    $webhook_url = get_setting('webhook_url', '');
    $webhook_events = get_setting('webhook_events', '');

    if (empty($webhook_url)) return;

    // Check if this event is in the configured events list
    $allowed_events = array_filter(array_map('trim', explode(',', $webhook_events)));
    if (!empty($allowed_events) && !in_array($event, $allowed_events) && !in_array('all', $allowed_events)) {
        return;
    }

    $payload = json_encode([
        'event' => $event,
        'timestamp' => gmdate('c'),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);

    // Send async webhook via curl (non-blocking)
    if (function_exists('curl_init')) {
        $ch = curl_init($webhook_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Event: ' . $event],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * Retourne la taille en octets du fichier de base de données.
 * @return int Taille en octets, ou 0 si le fichier n'existe pas
 */
function get_db_size(): int {
    $path = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../db/workflow.db';
    return file_exists($path) ? (int)filesize($path) : 0;
}
