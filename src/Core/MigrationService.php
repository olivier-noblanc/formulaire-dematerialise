<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Service de migrations SQLite.
 * Wraps the global db_migrate() function for testability and DI.
 */
final readonly class MigrationService
{
    public function __construct(private Database $database)
    {
    }

    /**
     * Applique toutes les migrations SQLite sur la connexion principale.
     */
    public function migrate(): void
    {
        $pdo = $this->database->getPdo();
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
