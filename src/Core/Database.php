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
        /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse */
        if (defined('TEST_MODE') && TEST_MODE) {
            return $this->getTestPdo();
        }

        if (!$this->pdo instanceof \PDO) {
            $pdo = new \PDO('sqlite:' . DB_PATH);
            $this->applyConnectionPragmas($pdo);
            $this->pdo = $pdo;

            // Migrations
            if (function_exists('db_migrate')) {
                db_migrate($this->pdo);
            }

            // Lazy cron (différé)
            register_shutdown_function(function (): void {
                $this->runDeferredCron();
            });
        }

        return $this->pdo;
    }

    /**
     * Handler de shutdown : exécute le cron différé après l'envoi de la réponse.
     *
     * F1 (audit 2026-09-16) : sans protection, PHP-FPM/IIS tue le script dès que
     * la réponse HTTP est envoyée (ou si le client coupe la connexion), ce qui
     * peut interrompre le cron en pleine exécution. On force donc, juste avant
     * le travail de fond :
     *   - ignore_user_abort(true) : continuer même si le client a coupé ;
     *   - fastcgi_finish_request() si disponible : flusher la réponse au client
     *     avant le cron (PHP-FPM). La fonction est absente en CLI et sous IIS
     *     FastCGI (php-cgi) — le script se termine alors normalement (fallback sûr).
     *
     * Le garde statique de CronService::runLazyCron() (réentrance) n'est pas modifié.
     */
    private function runDeferredCron(): void
    {
        if (!$this->pdo instanceof \PDO) {
            return;
        }

        if (!App::getInstance()->has(\App\Cron\CronService::class)) {
            return;
        }

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        App::cron()->runLazyCron();
    }

    private function getTestPdo(): \PDO
    {
        if (!$this->pdoTest instanceof \PDO) {
            $testDbPath = $GLOBALS['_test_db_path'] ?? dirname(__DIR__, 2) . '/db/workflow_test.db';
            $pdo = new \PDO('sqlite:' . $testDbPath);
            $this->applyConnectionPragmas($pdo);
            $this->pdoTest = $pdo;

            if (function_exists('db_migrate')) {
                db_migrate($this->pdoTest);
            }
        }

        return $this->pdoTest;
    }

    // ── SQLite admin operations ──────────────────────────────────

    /**
     * Applique les PRAGMA de connexion communs à toute connexion SQLite.
     *
     * R5 (audit 2026-09-14) : le mode WAL est garanti au niveau de la connexion,
     * et plus seulement par la migration `apply_schema_initial()`. Le PRAGMA
     * `journal_mode=WAL` est persistant sur le fichier, mais le garantir ici
     * couvre les connexions ouvertes sans passer par `db_migrate()` (et limite
     * les `SQLITE_BUSY` en cas d'accès concurrent). WAL est supporté nativement
     * par SQLite sous Windows/NTFS.
     *
     * Méthode publique pour pouvoir configurer toute connexion SQLite ad-hoc
     * (notamment les bases de test).
     */
    public function applyConnectionPragmas(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }

    /**
     * Re-enable foreign key enforcement (session-scoped in SQLite).
     *
     * Already set on connection, but useful to re-enable explicitly
     * before a transaction that depends on FK cascades.
     */
    public function enableForeignKeys(): void
    {
        $this->getPdo()->exec('PRAGMA foreign_keys = ON');
    }

    public function getPageCount(): int
    {
        $stmt = $this->getPdo()->query('PRAGMA page_count');
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    public function getFreelistCount(): int
    {
        $stmt = $this->getPdo()->query('PRAGMA freelist_count');
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    public function getPageSize(): int
    {
        $stmt = $this->getPdo()->query('PRAGMA page_size');
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    public function vacuum(): void
    {
        $this->getPdo()->exec('VACUUM');
    }

    // ── Transaction helpers ──────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->getPdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getPdo()->commit();
    }

    public function rollBack(): void
    {
        $this->getPdo()->rollBack();
    }

    public function release(): void
    {
        if ($this->pdo instanceof \PDO) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (\PDOException) {
                // @silent-ok: fallback cleanup on release — ignore rollback failure
            }
            $this->pdo = null;
        }

        if ($this->pdoTest instanceof \PDO) {
            try {
                if ($this->pdoTest->inTransaction()) {
                    $this->pdoTest->rollBack();
                }
            } catch (\PDOException) {
                // @silent-ok: fallback cleanup on release — ignore rollback failure
            }
            $this->pdoTest = null;
        }
    }
}
