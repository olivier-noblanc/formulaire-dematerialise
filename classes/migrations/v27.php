<?php
declare(strict_types=1);

/**
 * Migration v27: Index unique partiel sur les tokens actifs.
 *
 * Empêche la création de tokens doublons (submission_id, step_id, email)
 * tant qu'un token non validé existe déjà pour cette combinaison.
 * Corrige le bug où advanceWorkflow pouvait créer deux tokens pour
 * la même étape/email, dont un restait done_at IS NULL et générait
 * des relances parasites.
 *
 * @package Migrations
 */

function apply_migration_v27(PDO $pdo, int $current_version): int {
    $needs_v27 = ($current_version < 27) || ($current_version >= 900);
    if (!$needs_v27) {
        return $current_version;
    }

    try {
        $v27_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 27");
        if ($v27_stmt === false) {
            throw new \RuntimeException('v27: COUNT query failed');
        }
        $v27_done = (int) $v27_stmt->fetchColumn();
        if ($v27_done > 0) {
            return max($current_version, 27);
        }

        // Nettoyer les doublons existants avant de créer l'index
        // rowid est monotone (ordre d'insertion), fiable même si sent_at est identique (même seconde)
        $pdo->exec("
            DELETE FROM tokens WHERE done_at IS NULL AND id IN (
                SELECT t1.id FROM tokens t1
                INNER JOIN (
                    SELECT submission_id, step_id, email, MAX(rowid) as max_rid
                    FROM tokens WHERE done_at IS NULL
                    GROUP BY submission_id, step_id, email
                    HAVING COUNT(*) > 1
                ) t2 ON t1.submission_id = t2.submission_id
                    AND t1.step_id = t2.step_id
                    AND t1.email = t2.email
                    AND t1.rowid < t2.max_rid
            )
        ");

        // Index unique partiel : un seul token non validé par (submission, étape, email)
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_tokens_active_per_step_email
            ON tokens(submission_id, step_id, email)
            WHERE done_at IS NULL
        ");

        // Enregistrer la version
        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (27, datetime('now'))");
        $stmt->execute();

        return 27;
    } catch (PDOException $e) {
        error_log("Migration v27 failed: " . $e->getMessage());
        return $current_version;
    }
}
