<?php
declare(strict_types=1);

namespace App\Webhook;

use App\Core\Database;
use App\Settings\SettingsService;

/**
 * Service de notifications webhook et taille de la base de données.
 *
 * Extrait de lib/webhook.php — envoi webhook async et récupération taille DB.
 * Les fonctions globales dans lib/webhook.php délèguent maintenant ici.
 */
final class WebhookService
{
    private Database $db;
    private SettingsService $settings;

    public function __construct(Database $db, SettingsService $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * Envoie une notification webhook si configuré.
     *
     * @param array<string, mixed> $data
     */
    public function send(string $event, array $data): void
    {
        $webhook_url = $this->settings->get('webhook_url', '');
        $webhook_events = $this->settings->get('webhook_events', '');

        if (empty($webhook_url)) return;

        // Check if this event is in the configured events list
        $allowed_events = array_filter(array_map('trim', explode(',', $webhook_events)));
        if (!empty($allowed_events) && !in_array($event, $allowed_events, true) && !in_array('all', $allowed_events, true)) {
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
                CURLOPT_POSTFIELDS => (string) $payload,
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
     */
    public function getDbSize(): int
    {
        $path = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../../db/workflow.db';
        return file_exists($path) ? (int)filesize($path) : 0;
    }
}
