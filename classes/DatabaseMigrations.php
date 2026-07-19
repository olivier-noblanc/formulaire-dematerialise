<?php
declare(strict_types=1);

/**
 * Database migrations facade.
 *
 * Ce fichier orchestre les migrations SQLite. Toutes les migrations
 * sont désormais dans classes/migrations/ :
 *   - schema_initial.php    : création initiale des tables + seeding admin
 *   - seed_default_forms.php: seeding des 8 formulaires par défaut
 *   - v10.php à v22.php      : une migration par fichier
 *   - post_migration.php     : seeds différés post-v14
 *
 * Note : les migrations v1 à v9 ont été supprimées (plus aucune base
 * antérieure à v10 en production). Le schéma initial crée directement
 * les tables dans leur état post-v9 (PK TEXT/UUID).
 *
 * @package Migrations
 */

// ── Chargement des modules de migration ──
require_once __DIR__ . '/migrations/schema_initial.php';
require_once __DIR__ . '/migrations/seed_default_forms.php';
for ($v = 10; $v <= 30; $v++) {
    require_once __DIR__ . '/migrations/v' . sprintf('%02d', $v) . '.php';
}
require_once __DIR__ . '/migrations/post_migration.php';

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
 * Applique toutes les migrations SQLite dans l'ordre.
 *
 * Étapes :
 *   1. apply_schema_initial() — création des tables + seeding admin
 *   2. apply_migration_v10() à apply_migration_v22() — migrations versionnées
 *   3. apply_post_migration_fixes() — seeds différés
 *
 * @param PDO $pdo Connexion PDO
 */
function db_migrate(PDO $pdo): void {
    // ── 1. Création initiale des tables ──
    $seed_needed = false;
    $current_version = apply_schema_initial($pdo, $seed_needed);

    // ── 2. Migrations versionnées v10 à v24 ──
    $current_version = apply_migration_v10($pdo, $current_version);
    $current_version = apply_migration_v11($pdo, $current_version);
    $current_version = apply_migration_v12($pdo, $current_version);
    $current_version = apply_migration_v13($pdo, $current_version);
    $current_version = apply_migration_v14($pdo, $current_version);
    $current_version = apply_migration_v15($pdo, $current_version);
    $current_version = apply_migration_v16($pdo, $current_version);
    $current_version = apply_migration_v17($pdo, $current_version);
    $current_version = apply_migration_v18($pdo, $current_version);
    $current_version = apply_migration_v19($pdo, $current_version);
    $current_version = apply_migration_v20($pdo, $current_version);
    $current_version = apply_migration_v21($pdo, $current_version);
    $current_version = apply_migration_v22($pdo, $current_version);
    $current_version = apply_migration_v23($pdo, $current_version);
    $current_version = apply_migration_v24($pdo, $current_version);
    $current_version = apply_migration_v25($pdo, $current_version);
    $current_version = apply_migration_v26($pdo, $current_version);
    $current_version = apply_migration_v27($pdo, $current_version);
    $current_version = apply_migration_v28($pdo, $current_version);
    $current_version = apply_migration_v29($pdo, $current_version);
    $current_version = apply_migration_v30($pdo, $current_version);

    // ── 3. Post-migration fixes (seeds différés, etc.) ──
    apply_post_migration_fixes($pdo, $seed_needed);
}
