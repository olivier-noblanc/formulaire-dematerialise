<?php
declare(strict_types=1);

/**
 * Migration v29: Ajoute la colonne invalidated_at à la table tokens.
 *
 * Permet de distinguer un token "traité par le validateur" (done_at IS NOT NULL
 * AND invalidated_at IS NULL) d'un token "invalidé par régénération admin"
 * (done_at IS NOT NULL AND invalidated_at IS NOT NULL).
 *
 * @package Migrations
 */

function apply_migration_v29(PDO $pdo, int $current_version): int {
    $needs_v29 = ($current_version < 29) || ($current_version >= 900);
    if (!$needs_v29) {
        return $current_version;
    }

    try {
        $v29_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 29");
        if ($v29_stmt === false) {
            throw new \RuntimeException('v29: COUNT query failed');
        }
        $v29_done = (int) $v29_stmt->fetchColumn();
        if ($v29_done > 0) {
            return max($current_version, 29);
        }

        // Vérifier si la colonne existe déjà (idempotent)
        $cols_stmt = $pdo->query("PRAGMA table_info(tokens)");
        if ($cols_stmt === false) {
            throw new \RuntimeException('v29: PRAGMA table_info(tokens) failed');
        }
        $cols = $cols_stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasInvalidatedAt = false;
        foreach ($cols as $col) {
            if ($col['name'] === 'invalidated_at') {
                $hasInvalidatedAt = true;
                break;
            }
        }

        if (!$hasInvalidatedAt) {
            $pdo->exec("ALTER TABLE tokens ADD COLUMN invalidated_at DATETIME NULL");
        }

        // Enregistrer la version
        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (29, datetime('now'))");
        $stmt->execute();

        return 29;
    } catch (PDOException $e) {
        error_log("Migration v29 failed: " . $e->getMessage());
        return $current_version;
    }
}
