<?php
declare(strict_types=1);

/** Strict, non-nullable submissions.status and NULL-safe guards. */
function apply_migration_v43(PDO $pdo, int $current_version): int
{
    if ($current_version >= 43 && $current_version < 900) return $current_version;
    $done = $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 43');
    if ($done === false) throw new RuntimeException('v43: version query failed');
    $already = (int) $done->fetchColumn(); $done = null;
    if ($already > 0) return max($current_version, 43);
    $badQuery = $pdo->query("SELECT id, typeof(status) AS status_type, status FROM submissions WHERE status IS NULL OR status NOT IN ('en_cours','valide','refuse','annule') LIMIT 10");
    if ($badQuery === false) throw new RuntimeException('v43: preflight query failed');
    $bad = $badQuery->fetchAll(PDO::FETCH_ASSOC); $badQuery = null;
    if ($bad !== []) throw new RuntimeException('v43 blocked: invalid submissions.status values');
    $fkQuery = $pdo->query('PRAGMA foreign_keys');
    if ($fkQuery === false) throw new RuntimeException('v43: foreign key query failed');
    $fk = (int) $fkQuery->fetchColumn() === 1; $fkQuery = null;
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $sqlQuery = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='submissions'");
        if ($sqlQuery === false) throw new RuntimeException('v43: schema query failed');
        $sql = (string) $sqlQuery->fetchColumn(); $sqlQuery = null;
        if ($sql === '') throw new RuntimeException('v43: missing submissions table');
        $strict = preg_replace('/status\s+TEXT\s+DEFAULT\s+\'en_cours\'(?:\s+CHECK\s*\(status\s+IN\s*\([^)]*\)\))?/i', "status TEXT NOT NULL DEFAULT 'en_cours' CHECK (status IN ('en_cours', 'valide', 'refuse', 'annule'))", $sql);
        if (!is_string($strict)) throw new RuntimeException('v43: cannot rewrite submissions');
        $strict = preg_replace('/\bsubmissions\b/', 'submissions_new', $strict, 1);
        if (!is_string($strict)) throw new RuntimeException('v43: cannot rename submissions');
        $pdo->exec('DROP TABLE IF EXISTS submissions_new');
        $pdo->exec($strict);
        $pdo->exec('INSERT INTO submissions_new SELECT * FROM submissions');
        $pdo->exec('DROP TABLE submissions');
        $pdo->exec('ALTER TABLE submissions_new RENAME TO submissions');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_active_per_form_user ON submissions(form_id, submitted_by) WHERE status = 'en_cours' AND closed_at IS NULL");
        $pdo->exec('DROP TRIGGER IF EXISTS check_submissions_status_insert');
        $pdo->exec('DROP TRIGGER IF EXISTS check_submissions_status_update');
        $pdo->exec("CREATE TRIGGER check_submissions_status_insert BEFORE INSERT ON submissions WHEN NEW.status IS NULL OR NEW.status NOT IN ('en_cours','valide','refuse','annule') BEGIN SELECT RAISE(ABORT, 'Invalid submissions.status'); END");
        $pdo->exec("CREATE TRIGGER check_submissions_status_update BEFORE UPDATE OF status ON submissions WHEN NEW.status IS NULL OR NEW.status NOT IN ('en_cours','valide','refuse','annule') BEGIN SELECT RAISE(ABORT, 'Invalid submissions.status'); END");
        $pdo->exec("INSERT INTO schema_version (version, applied_at) VALUES (43, datetime('now'))");
        $pdo->exec('COMMIT');
        return 43;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    } finally { if ($fk) $pdo->exec('PRAGMA foreign_keys = ON'); }
}
