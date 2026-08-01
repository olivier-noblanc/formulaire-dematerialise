<?php

declare(strict_types=1);

namespace App\Webhook;

/**
 * Service utilitaire — taille de la base de données.
 */
final class WebhookService
{
    /**
     * Retourne la taille en octets du fichier de base de données.
     */
    public function getDbSize(): int
    {
        $path = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;
        return file_exists($path) ? (int) filesize($path) : 0;
    }
}
