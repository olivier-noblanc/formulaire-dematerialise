<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Service de migrations SQLite.
 * Wraps the global db_migrate() function for testability and DI.
 */
final class MigrationService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Applique toutes les migrations SQLite sur la connexion principale.
     */
    public function migrate(): void
    {
        $pdo = $this->db->getPdo();
        if (function_exists('db_migrate')) {
            db_migrate($pdo);
        }
    }

    /**
     * Applique les migrations sur une connexion PDO spécifique (pour les tests).
     */
    public function migratePdo(\PDO $pdo): void
    {
        if (function_exists('db_migrate')) {
            db_migrate($pdo);
        }
    }
}
