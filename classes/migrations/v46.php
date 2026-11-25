<?php
declare(strict_types=1);

use App\Enum\NotifyWho;

/**
 * v46 — contrainte SQL sur `alert_rules.notify_who`.
 *
 * `notify_who` accepte deux formes exclusives :
 *  - l'une des cinq sentinelles de App\Enum\NotifyWho ;
 *  - une adresse email personnalisée validée par FILTER_VALIDATE_EMAIL.
 *
 * SQLite ne sait pas ajouter une CHECK sur une table existante : la migration
 * reconstruit la table (même pattern que v44). Elle est rétrocompatible et
 * strictement fail-closed : un préflight PHP refuse de migrer si une ligne
 * existante viole le contrat — aucune normalisation, aucune perte silencieuse.
 *
 * La forme SQL de l'email (`LIKE '_%@_%.%'`) est volontairement plus permissive
 * que FILTER_VALIDATE_EMAIL : la CHECK est un filet de sécurité qui refuse les
 * valeurs vides ou les sentinelles fantaisistes, jamais une adresse valide.
 */
function apply_migration_v46(PDO $pdo, int $current_version): int
{
    if ($current_version >= 46 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 46');
    if ($done === false) {
        throw new RuntimeException('v46: version query failed');
    }
    $already = (int) $done->fetchColumn();
    $done = null;
    if ($already > 0) {
        return max($current_version, 46);
    }

    // ── Préflight strict : sentinelle ou email valide, rien d'autre ──
    $rows = $pdo->query('SELECT id, notify_who FROM alert_rules');
    if ($rows === false) {
        throw new RuntimeException('v46: preflight query failed');
    }
    $invalid = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = (string) $row['notify_who'];
        if (!NotifyWho::isSentinel($value) && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $invalid[] = (string) $row['id'];
        }
    }
    $rows = null;
    if ($invalid !== []) {
        throw new RuntimeException(
            'v46 blocked: invalid alert_rules.notify_who for rule(s) '
            . implode(', ', array_slice($invalid, 0, 10))
        );
    }

    // PRAGMA foreign_keys est sans effet dans une transaction : le positionner
    // avant le BEGIN pour éviter le cascade delete des alert_log à la DROP.
    $fkQuery = $pdo->query('PRAGMA foreign_keys');
    if ($fkQuery === false) {
        throw new RuntimeException('v46: foreign key query failed');
    }
    $foreignKeys = (int) $fkQuery->fetchColumn() === 1;
    $fkQuery = null;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $sentinels = implode("','", NotifyWho::values());
        $pdo->exec('DROP TABLE IF EXISTS alert_rules_new');
        $pdo->exec(
            "CREATE TABLE alert_rules_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, "
            . "days_before INTEGER NOT NULL DEFAULT 5 CHECK (days_before > 0), "
            . "condition_type TEXT NOT NULL DEFAULT 'steps_incomplete' CHECK (condition_type = 'steps_incomplete'), "
            . "notify_who TEXT NOT NULL DEFAULT 'admin' CHECK (notify_who IN ('{$sentinels}') OR notify_who LIKE '_%@_%.%'), "
            . "label TEXT NOT NULL, actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0,1)), "
            . "created_at DATETIME DEFAULT CURRENT_TIMESTAMP, "
            . 'FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)'
        );
        $pdo->exec(
            'INSERT INTO alert_rules_new (id, form_id, days_before, condition_type, notify_who, label, actif, created_at) '
            . 'SELECT id, form_id, days_before, condition_type, notify_who, label, actif, created_at FROM alert_rules'
        );
        $pdo->exec('DROP TABLE alert_rules');
        $pdo->exec('ALTER TABLE alert_rules_new RENAME TO alert_rules');

        $check = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='alert_rules'");
        if ($check === false) {
            throw new RuntimeException('v46: schema query failed');
        }
        $sql = (string) $check->fetchColumn();
        $check = null;
        if (!str_contains($sql, 'notify_who IN')) {
            throw new RuntimeException('v46: notify_who CHECK missing after rebuild');
        }

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (46, datetime('now'))");
        $pdo->exec('COMMIT');
        return 46;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    } finally {
        if ($foreignKeys) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}