<?php
declare(strict_types=1);

namespace App\Core;

use App\Contract\DatabaseInterface;

/**
 * Gestion de la connexion SQLite (singleton PDO).
 */
final class Database implements DatabaseInterface
{
    private ?\PDO $pdo = null;
    private ?\PDO $pdoTest = null;

    public function getPdo(): \PDO
    {
        // Mode test
        if (defined('TEST_MODE') && TEST_MODE) {
            return $this->getTestPdo();
        }

        if ($this->pdo === null) {
            $this->pdo = new \PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Migrations
            if (function_exists('db_migrate')) {
                db_migrate($this->pdo);
            }

            // Lazy cron (différé)
            register_shutdown_function(function (): void {
                if ($this->pdo !== null && function_exists('run_lazy_cron')) {
                    run_lazy_cron($this->pdo);
                }
            });
        }

        return $this->pdo;
    }

    private function getTestPdo(): \PDO
    {
        if ($this->pdoTest === null) {
            $testDbPath = $GLOBALS['_test_db_path'] ?? dirname(__DIR__, 2) . '/db/workflow_test.db';
            $this->pdoTest = new \PDO('sqlite:' . $testDbPath);
            $this->pdoTest->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            if (function_exists('db_migrate')) {
                db_migrate($this->pdoTest);
            }
        }

        return $this->pdoTest;
    }

    public function release(): void
    {
        if ($this->pdo !== null) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (\PDOException $e) {
                // Ignorer
            }
            $this->pdo = null;
        }

        if ($this->pdoTest !== null) {
            try {
                if ($this->pdoTest->inTransaction()) {
                    $this->pdoTest->rollBack();
                }
            } catch (\PDOException $e) {
                // Ignorer
            }
            $this->pdoTest = null;
        }
    }
}
