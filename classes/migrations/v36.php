<?php
declare(strict_types=1);

/**
 * Migration v36: Relance personnalisable par formulaire.
 *
 * Les paramètres de relance (delai_relance_h, relance_max) passent de
 * settings globaux → colonnes par formulaire, pour permettre de régler
 * le circuit de relance indépendamment sur chaque formulaire.
 *
 * Nouvelles colonnes sur `forms` :
 *
 * 1. relance_delai_h INTEGER DEFAULT 48 CHECK (relance_delai_h BETWEEN 1 AND 720)
 *    — délai (heures) avant la première relance, en complément du délai
 *      ou du défaut existant (48 h).
 * 2. relance_max     INTEGER DEFAULT 3  CHECK (relance_max BETWEEN 0 AND 20)
 *    — nombre maximum de relances envoyées (0 = aucune relance).
 *
 * CHECK constraints SQL obligatoires (règle AGENTS.md #8). Pas de
 * rétrocompatibilité NULL : les formulaires existants reçoivent les
 * DEFAULT 48 / 3 lors du rebuild.
 *
 * Nettoyage settings : après le rebuild, suppression des clés globales
 * `delai_relance_h` et `relance_max` de la table `settings` (désormais
 * portées par formulaire).
 *
 * La table `forms` est reconstruite via le pattern v33 (DROP + CREATE +
 * INSERT + RENAME), avec libération explicite des PDOStatement avant
 * chaque DDL (règle AGENTS.md SQLITE_LOCKED).
 *
 * @package Migrations
 */

function apply_migration_v36(PDO $pdo, int $current_version): int {
    $needs_v36 = ($current_version < 36) || ($current_version >= 900);
    if (!$needs_v36) {
        return $current_version;
    }

    try {
        $v36_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 36");
        if ($v36_stmt === false) {
            throw new \RuntimeException('v36: COUNT query failed');
        }
        $v36_done = (int) $v36_stmt->fetchColumn();
        // CS-06 : libérer le statement avant le prochain DDL
        $v36_stmt = null;
        if ($v36_done > 0) {
            return max($current_version, 36);
        }

        $dbListStmt = $pdo->query('PRAGMA database_list');
        if ($dbListStmt === false) {
            throw new \RuntimeException('v36: PRAGMA database_list failed');
        }
        $dbPathRaw = $dbListStmt->fetchColumn(2);
        $dbListStmt = null;
        if ($dbPathRaw === false || $dbPathRaw === null) {
            throw new \RuntimeException('v36: PRAGMA database_list returned no path');
        }
        $dbPath = (string) $dbPathRaw;

        $rebuild = new PDO('sqlite:' . $dbPath);
        $rebuild->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rebuild->exec('PRAGMA busy_timeout = 10000');
        $rebuild->exec('PRAGMA foreign_keys = OFF');

        // ── Self-healing : nettoyer la _new orpheline d'une panne précédente ──
        $hasOldStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='forms'");
        $hasOld = $hasOldStmt !== false && $hasOldStmt->fetchColumn() !== false;
        $hasOldStmt = null;

        $hasNewStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='forms_new'");
        $hasNew = $hasNewStmt !== false && $hasNewStmt->fetchColumn() !== false;
        $hasNewStmt = null;

        if (!$hasOld && $hasNew) {
            $rebuild->exec('ALTER TABLE forms_new RENAME TO forms');
        } elseif ($hasOld && $hasNew) {
            $rebuild->exec('DROP TABLE forms_new');
        }

        // ── 1. Rebuild forms (nouvelles colonnes relance + CHECK) ──
        $rebuild->exec('DROP TABLE IF EXISTS forms_new');
        $rebuild->exec("CREATE TABLE forms_new (
            id TEXT PRIMARY KEY NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            actif INTEGER DEFAULT 1 CHECK (actif IN (0, 1)),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deadline_field TEXT DEFAULT '',
            relance_delai_h INTEGER DEFAULT 48 CHECK (relance_delai_h BETWEEN 1 AND 720),
            relance_max INTEGER DEFAULT 3 CHECK (relance_max BETWEEN 0 AND 20)
        )");
        $rebuild->exec('INSERT INTO forms_new (id, slug, label, description, actif, created_at, deadline_field)
                        SELECT id, slug, label, description, actif, created_at, deadline_field FROM forms');
        $rebuild->exec('DROP TABLE forms');
        $rebuild->exec('ALTER TABLE forms_new RENAME TO forms');

        // ── 2. Nettoyage settings (clés devenues par formulaire) ──
        // DML uniquement (pas de DDL) : pas de risque SQLITE_LOCKED, mais on
        // publie sur la connexion $pdo (même base) après le rebuild.
        $pdo->exec("DELETE FROM settings WHERE key IN ('delai_relance_h', 'relance_max')");

        // ── FK integrity check ──
        $rebuild->exec('PRAGMA foreign_keys = ON');
        $fkStmt = $rebuild->query('PRAGMA foreign_key_check');
        $fkErrors = $fkStmt !== false ? $fkStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $fkStmt = null;
        $rebuild = null;

        // P0-7 (2026-09-03) : le marquage schema_version ne se fait qu'APRÈS
        // validation FK. Avant : l'INSERT précèdait le throw — en cas de FK
        // cassée, la version 36 était marquée puis la migration sautée au
        // prochain run malgré l'état invalide (jamais rejouée).
        if ($fkErrors !== []) {
            throw new \RuntimeException('v36: FK integrity broken: ' . json_encode($fkErrors));
        }

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (36, datetime('now'))");

        return 36;
    } catch (\PDOException $e) {
        // @silent-ok: log-only — la migration sera retentée au prochain appel
        error_log("Migration v36 failed: " . $e->getMessage());
        return $current_version;
    }
}
