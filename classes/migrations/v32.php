<?php
declare(strict_types=1);

/**
 * Migration v32: Index unique partiel sur les soumissions en cours.
 *
 * CS-07 (audit 2026-07-26) : l'invariant "une seule soumission en_cours
 * par form + demandeur" n'était garanti que côté applicatif (FormController
 * avant INSERT). Une race condition entre deux requêtes concurrentes pouvait
 * créer deux soumissions en_cours pour le même (form_id, submitted_by).
 *
 * Cette migration ajoute un index unique partiel SQLite :
 *   CREATE UNIQUE INDEX idx_submissions_active_per_form_user
 *   ON submissions(form_id, submitted_by)
 *   WHERE status = 'en_cours' AND closed_at IS NULL
 *
 * La condition sur closed_at IS NULL gère le cas où une soumission a été
 * refusée/annulée (closed_at set, status != en_cours) et l'agent veut
 * soumettre à nouveau : la ligne précédente ne bloque pas la nouvelle.
 *
 * Préalable : nettoyer les éventuels doublons existants. On garde le plus
 * ancien (MIN(rowid)) par (form_id, submitted_by) et on supprime les autres.
 * Si une soumission fermée (closed_at NOT NULL) coexiste avec une en cours,
 * on la garde — seule la contrainte sur les en_cours est concernée.
 *
 * @package Migrations
 */

function apply_migration_v32(PDO $pdo, int $current_version): int {
    $needs_v32 = ($current_version < 32) || ($current_version >= 900);
    if (!$needs_v32) {
        return $current_version;
    }

    try {
        $v32_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 32");
        if ($v32_stmt === false) {
            throw new \RuntimeException('v32: COUNT query failed');
        }
        $v32_done = (int) $v32_stmt->fetchColumn();
        // CS-06 : libérer le statement avant le prochain DDL
        $v32_stmt = null;
        if ($v32_done > 0) {
            return max($current_version, 32);
        }

        // Nettoyer les doublons existants avant de créer l'index UNIQUE.
        // On ne touche qu'aux soumissions en_cours (closed_at IS NULL, status='en_cours').
        // On garde le plus ancien (MIN(rowid)) par (form_id, submitted_by).
        $pdo->exec("
            DELETE FROM submissions WHERE id IN (
                SELECT s1.id FROM submissions s1
                INNER JOIN (
                    SELECT form_id, submitted_by, MIN(rowid) as min_rid
                    FROM submissions
                    WHERE status = 'en_cours' AND closed_at IS NULL
                      AND submitted_by IS NOT NULL AND submitted_by <> ''
                    GROUP BY form_id, submitted_by
                    HAVING COUNT(*) > 1
                ) s2 ON s1.form_id = s2.form_id
                    AND s1.submitted_by = s2.submitted_by
                    AND s1.rowid > s2.min_rid
                WHERE s1.status = 'en_cours' AND s1.closed_at IS NULL
            )
        ");

        // Index unique partiel : une seule soumission en_cours par (form_id, submitted_by)
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_active_per_form_user
            ON submissions(form_id, submitted_by)
            WHERE status = 'en_cours' AND closed_at IS NULL
        ");

        // Enregistrer la version
        $stmt = $pdo->prepare("INSERT INTO schema_version (version, applied_at) VALUES (32, datetime('now'))");
        $stmt->execute();

        return 32;
    } catch (PDOException $e) {
        // @silent-ok: log-only — la migration sera retentée au prochain appel
        error_log("Migration v32 failed: " . $e->getMessage());
        return $current_version;
    }
}
