<?php
declare(strict_types=1);

/**
 * Migration v37: B1 (audit adversarial 2026-09-14) — l'index unique partiel des
 * tokens actifs doit exclure les tokens invalidés.
 *
 * v27 (puis le rebuild v33) créait :
 *   CREATE UNIQUE INDEX idx_tokens_active_per_step_email
 *     ON tokens(submission_id, step_id, email) WHERE done_at IS NULL
 *
 * Or un token invalidé par RGPD porte `done_at IS NULL` + `invalidated_at NOT NULL`
 * (cf. TokenWriteQueriesTrait::invalidateActiveByEmail). Il restait donc membre de
 * l'index partiel et bloquait (contrainte 23000) la recréation d'un nouveau token
 * actif pour la même (submission, step, email) → après invalidation RGPD, l'étape
 * restait bloquée à vie et la soumission invisible.
 *
 * Correction : la condition partielle devient
 * `WHERE done_at IS NULL AND invalidated_at IS NULL`, alignant l'index sur la
 * sémantique de `hasPendingDuplicate()` (seul un token actif non invalidé interdit
 * un doublon). La recréation d'un token par WorkflowAdvancer après invalidation
 * (B1) devient alors possible.
 *
 * @package Migrations
 */

function apply_migration_v37(PDO $pdo, int $current_version): int {
    $needs_v37 = ($current_version < 37) || ($current_version >= 900);
    if (!$needs_v37) {
        return $current_version;
    }

    try {
        $v37_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 37");
        if ($v37_stmt === false) {
            throw new \RuntimeException('v37: COUNT query failed');
        }
        $v37_done = (int) $v37_stmt->fetchColumn();
        // CS-06 (audit 2026-07-26) : libérer le statement avant le prochain DDL
        // (règle SQLITE_LOCKED intra-processus, AGENTS.md).
        $v37_stmt = null;
        if ($v37_done > 0) {
            return max($current_version, 37);
        }

        // DROP + CREATE de l'index partiel sur la même connexion. Aucun
        // PDOStatement n'est laissé ouvert sur `tokens` avant le DDL.
        $pdo->exec('DROP INDEX IF EXISTS idx_tokens_active_per_step_email');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tokens_active_per_step_email
            ON tokens(submission_id, step_id, email)
            WHERE done_at IS NULL AND invalidated_at IS NULL");

        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (37, datetime('now'))");

        return 37;
    } catch (\PDOException $e) {
        // @silent-ok: log-only — la migration sera retentée au prochain appel
        error_log("Migration v37 failed: " . $e->getMessage());
        return $current_version;
    }
}