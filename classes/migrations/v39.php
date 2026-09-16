<?php
declare(strict_types=1);

/**
 * Migration v39: Rejeu manuel des envois en échec définitif (opérateur).
 *
 * Ajoute à `mail_log` le compteur des rejeux manuels :
 *   - manual_replay_count INTEGER NOT NULL DEFAULT 0
 *     — nombre de rejeux déclenchés MANUELLEMENT par un opérateur sur une ligne
 *       `failed` (plafonné côté code, cf. MailRepository::claimFailedForManualReplay).
 *       Champ distinct de `attempts` (tentatives automatiques du worker) : le
 *       rejeu manuel est un override opérateur et ne doit pas être confondu avec
 *       le backoff automatique.
 *
 * SQLite accepte `ALTER TABLE ADD COLUMN ... NOT NULL DEFAULT 0` (contrairement
 * à NOT NULL sans défaut) : aucun rebuild de table n'est nécessaire. L'opération
 * est idempotente (self-healing si la colonne existe déjà mais que le marquage
 * schema_version a échoué).
 *
 * @package Migrations
 */

function apply_migration_v39(PDO $pdo, int $current_version): int {
    $needs_v39 = ($current_version < 39) || ($current_version >= 900);
    if (!$needs_v39) {
        return $current_version;
    }

    try {
        $v39_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 39");
        if ($v39_stmt === false) {
            throw new \RuntimeException('v39: COUNT query failed');
        }
        $v39_done = (int) $v39_stmt->fetchColumn();
        // Libérer le statement avant tout DDL (règle SQLITE_LOCKED intra-processus).
        $v39_stmt = null;
        if ($v39_done > 0) {
            return max($current_version, 39);
        }

        // Idempotence / self-healing : ne pas rejouer l'ADD COLUMN s'il existe
        // déjà (panne survenue entre l'ALTER et le marquage schema_version).
        $cols_stmt = $pdo->query('PRAGMA table_info(mail_log)');
        $has_column = false;
        if ($cols_stmt !== false) {
            foreach ($cols_stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                if (($col['name'] ?? '') === 'manual_replay_count') {
                    $has_column = true;
                    break;
                }
            }
        }
        $cols_stmt = null;

        if (!$has_column) {
            $pdo->exec('ALTER TABLE mail_log ADD COLUMN manual_replay_count INTEGER NOT NULL DEFAULT 0');
        }

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (39, datetime('now'))");

        return 39;
    } catch (\PDOException $e) {
        // @silent-ok: log-only — la migration sera retentée au prochain appel
        error_log("Migration v39 failed: " . $e->getMessage());
        return $current_version;
    }
}