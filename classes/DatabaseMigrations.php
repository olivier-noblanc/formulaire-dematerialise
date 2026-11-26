<?php
declare(strict_types=1);

/**
 * Database migrations facade.
 *
 * Ce fichier orchestre les migrations SQLite. Toutes les migrations
 * sont désormais dans classes/migrations/ :
 *   - schema_initial.php    : création initiale complète v42 + seeds
 *   - v43.php               : statut submissions NOT NULL
 *   - v44.php               : form_fields.filled_by / visibility NOT NULL
 *   - v45.php               : suppression de l'index unique legacy des
 *                             soumissions actives
 *   - v46.php               : contrainte CHECK sur alert_rules.notify_who
 *   - v47.php               : ordres entiers >= 0 et destinataires durcis
 *   - v48.php               : unicité de form_fields.field_name par formulaire
 *
 * Les versions v1 à v41 sont squashées dans la baseline 42.
 *
 * @package Migrations
 */

// ── Chargement des modules de migration ──
require_once __DIR__ . '/migrations/schema_initial.php';
require_once __DIR__ . '/migrations/v43.php';
require_once __DIR__ . '/migrations/v44.php';
require_once __DIR__ . '/migrations/v45.php';
require_once __DIR__ . '/migrations/v46.php';
require_once __DIR__ . '/migrations/v47.php';
require_once __DIR__ . '/migrations/v48.php';

// ── Helper query (utilisé par toutes les migrations) ──

/**
 * Exécute une requête SQL sur la connexion PDO et vérifie le résultat.
 * Centralise les ->query() avec check false.
 *
 * @param PDO    $pdo Connexion PDO
 * @param string $sql  Requête SQL
 * @return PDOStatement Statement exécutable
 * @throws PDOException Si la requête échoue
 */
function _dbm_q(PDO $pdo, string $sql): PDOStatement {
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new PDOException("Échec de la requête: {$sql}");
    }
    return $stmt;
}

// ── Migration orchestrator ──

/**
 * Execute one migration and refuse to continue unless it reaches its target.
 *
 * @param callable(PDO, int): int $migration
 */
function _dbm_apply_required(PDO $pdo, int $current_version, callable $migration, int $target_version): int
{
    $next_version = $migration($pdo, $current_version);
    // A database may already be beyond this migration; that is a successful
    // no-op. Only a returned lower version means the migration failed.
    if ($next_version < $target_version) {
        throw new RuntimeException("Migration v{$target_version} did not reach version {$target_version}");
    }

    return $next_version;
}

/**
 * Applique toutes les migrations SQLite dans l'ordre.
 *
 * Étapes : baseline v42 puis migrations v43, v44, v45, v46, v47 et v48.
 *
 * @param PDO $pdo Connexion PDO
 */
function db_migrate(PDO $pdo): void {
    $current_version = apply_schema_initial($pdo);
    $current_version = _dbm_apply_required($pdo, $current_version, 'apply_migration_v43', 43);
    $current_version = _dbm_apply_required($pdo, $current_version, 'apply_migration_v44', 44);
    $current_version = _dbm_apply_required($pdo, $current_version, 'apply_migration_v45', 45);
    $current_version = _dbm_apply_required($pdo, $current_version, 'apply_migration_v46', 46);
    _dbm_apply_required($pdo, $current_version, 'apply_migration_v47', 47);
    _dbm_apply_required($pdo, $current_version, 'apply_migration_v48', 48);
}
