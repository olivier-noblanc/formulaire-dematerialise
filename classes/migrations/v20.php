<?php
declare(strict_types=1);

/**
 * Migration v20: Supprime définitivement la table drafts.
 *
 * Le système de brouillons (P-02) a été supprimé en v5.35.0.
 * Cette migration DROP la table pour nettoyer la base — pas de rétrocompat.
 *
 * @package Migrations
 */

function apply_migration_v20(PDO $pdo, int $current_version): int {
    $needs_v20 = ($current_version < 20) || ($current_version >= 900);
    if ($needs_v20) {
        try {
            $v20_done = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 20")->fetchColumn();
            if ($v20_done > 0) return max($current_version, 20);

            // Supprimer définitivement la table drafts
            $pdo->exec("DROP TABLE IF EXISTS drafts");

            $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([20]);
            return 20;
        } catch (PDOException $e) {
            error_log('[db_migrate] v20 FAILED: ' . $e->getMessage());
        }
    }
    return $current_version;
}
