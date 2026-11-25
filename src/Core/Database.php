<?php

declare(strict_types=1);

namespace App\Core;

use App\Contract\DatabaseInterface;

/**
 * Gestion de la connexion SQLite (singleton PDO).
 */
final class Database implements DatabaseInterface
{
    /**
     * Délai d'attente SQLite sur un verrou (ms) — valeur unique du projet.
     * Sans lui, un accès concurrent (cron + requête web) échoue immédiatement
     * en SQLITE_BUSY au lieu d'attendre la fin de la transaction concurrente.
     */
    public const int BUSY_TIMEOUT_MS = 5000;

    /**
     * Première version de SQLite exposant `PRAGMA trusted_schema` (3.31.0,
     * janvier 2020). Sur une version antérieure le PRAGMA est inconnu : il est
     * silencieusement ignoré et ne peut donc pas être garanti (voir
     * CHANGELOG/TODO pour la limitation documentée).
     */
    public const string TRUSTED_SCHEMA_MIN_SQLITE = '3.31.0';

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
        self::applySessionPragmas($pdo);
        $pdo->exec('PRAGMA journal_mode = WAL');
    }

    /**
     * Applique les PRAGMA de durcissement **de session** à une connexion SQLite.
     *
     * Point central unique : toute connexion applicative (connexion réelle,
     * connexion de test, script CLI, fichier de sauvegarde ouvert en lecture)
     * doit passer par ici plutôt que de recopier les PRAGMA.
     *
     * - `foreign_keys = ON` : SQLite les désactive par défaut, y compris pour
     *   les `ON DELETE CASCADE` du schéma.
     * - `busy_timeout = BUSY_TIMEOUT_MS` : attente au lieu d'un SQLITE_BUSY
     *   immédiat en accès concurrent.
     * - `trusted_schema = OFF` (SQLite ≥ 3.31.0 seulement) : interdit au
     *   schéma (vue, trigger, CHECK, colonne générée) d'invoquer une fonction
     *   non « innocuous ». Sur une version plus ancienne, la détection renvoie
     *   false et le PRAGMA n'est pas posé : la limitation est documentée et
     *   testée (DatabaseTest::testApplySessionPragmasDisablesTrustedSchema…).
     *
     * Le mode journal n'est **pas** touché ici : cette méthode sert aussi à
     * durcir des fichiers de sauvegarde ouverts en lecture, qu'un passage en
     * WAL modifierait de façon persistante (voir applyConnectionPragmas()).
     */
    public static function applySessionPragmas(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        if (self::sqliteSupportsTrustedSchema(self::sqliteVersion($pdo))) {
            $pdo->exec('PRAGMA trusted_schema = OFF');
        }
    }

    /**
     * `PRAGMA trusted_schema` existe-t-il dans cette version de SQLite ?
     * Séparé de la connexion pour être testable par matrice de versions.
     */
    public static function sqliteSupportsTrustedSchema(string $sqliteVersion): bool
    {
        return version_compare($sqliteVersion, self::TRUSTED_SCHEMA_MIN_SQLITE, '>=');
    }

    /**
     * Version SQLite de la connexion (« 3.53.2 »), sans requête : évite
     * d'ouvrir un statement (et le verrou de lecture associé) juste pour
     * connaître la version du moteur.
     */
    private static function sqliteVersion(\PDO $pdo): string
    {
        return (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
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
