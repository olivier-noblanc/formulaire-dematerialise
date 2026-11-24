<?php
declare(strict_types=1);

/**
 * Drop the legacy global unique index on active submissions.
 *
 * v34 dropped `idx_submissions_active_per_form_user`, but the pre-squash v43
 * recreated it and the squash removed v34. A database that already applied the
 * old v43 keeps `schema_version = 43`, so the corrected v43 never re-runs and
 * the index survives — silently rejecting a second active submission for the
 * same `form_id`/`submitted_by` pair. This migration removes it for those
 * databases; `schema_initial.php` never creates it, so fresh installs are no-op.
 */
function apply_migration_v45(PDO $pdo, int $current_version): int
{
    if ($current_version >= 45 && $current_version < 900) {
        return $current_version;
    }

    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 45');
    if ($done === false) {
        throw new RuntimeException('v45: version query failed');
    }
    $already = (int) $done->fetchColumn();
    $done = null;
    if ($already > 0) {
        return max($current_version, 45);
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('DROP INDEX IF EXISTS idx_submissions_active_per_form_user');
        $left = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_submissions_active_per_form_user'");
        if ($left === false) {
            throw new RuntimeException('v45: index query failed');
        }
        $remaining = (int) $left->fetchColumn();
        $left = null;
        if ($remaining !== 0) {
            throw new RuntimeException('v45: legacy unique index still present');
        }
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (45, datetime('now'))");
        $pdo->exec('COMMIT');
        return 45;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}
