<?php
declare(strict_types=1);

namespace App\Webhook;

use App\Core\Database;

/**
 * Service utilitaire — taille de la base de données.
 */
final class WebhookService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
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
