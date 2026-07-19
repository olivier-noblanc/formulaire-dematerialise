<?php
declare(strict_types=1);

/**
 * Migration v30: Constraints CHECK sur colonnes enum.
 *
 * Approche split :
 * - form_fields.filled_by, form_fields.visibility, admin_requests.status → CHECK via rebuild
 * - submissions.status, tokens.action → triggers
 *
 * Utilise $rebuild (connexion séparée) car $pdo du bootstrap tient un lock
 * SQLITE_LOCKED (error 6) sur form_fields — probablement causé par le PDO
 * ouvert dans helpers.php::getBaseUrl() qui n'est jamais fermé.
 *
 * Self-healing + version INSERT en dernier sur $rebuild.
 *
 * @package Migrations
 */

function apply_migration_v30(PDO $pdo, int $current_version): int {
    $needs_v30 = ($current_version < 30) || ($current_version >= 900);
    if (!$needs_v30) {
        return $current_version;
    }

    try {
        $v30_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 30");
        if ($v30_stmt === false) {
            throw new \RuntimeException('v30: COUNT query failed');
        }
        $v30_done = (int) $v30_stmt->fetchColumn();
        if ($v30_done > 0) {
            return max($current_version, 30);
        }

        // ══════════════════════════════════════════════════════════
        // 1. $rebuild : connexion séparée pour tout le DDL
        // ══════════════════════════════════════════════════════════
        $dbListStmt = $pdo->query('PRAGMA database_list');
        if ($dbListStmt === false) {
            throw new \RuntimeException('v30: PRAGMA database_list failed');
        }
        $dbPath = $dbListStmt->fetchColumn(2);

        $rebuild = new PDO('sqlite:' . $dbPath);
        $rebuild->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rebuild->exec('PRAGMA busy_timeout = 10000');
        $rebuild->exec('PRAGMA foreign_keys = OFF');

        // Self-healing : nettoyer les _new orphelines d'une panne précédente
        $hasFFStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='form_fields'");
        $hasFF = $hasFFStmt !== false ? $hasFFStmt->fetchColumn() : false;
        $hasFFNewStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='form_fields_new'");
        $hasFFNew = $hasFFNewStmt !== false ? $hasFFNewStmt->fetchColumn() : false;
        if (!$hasFF && $hasFFNew) {
            $rebuild->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
            $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
            $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id, filled_by)');
        } elseif ($hasFF && $hasFFNew) {
            $rebuild->exec('DROP TABLE form_fields_new');
        }

        $hasARStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_requests'");
        $hasAR = $hasARStmt !== false ? $hasARStmt->fetchColumn() : false;
        $hasARNewStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admin_requests_new'");
        $hasARNew = $hasARNewStmt !== false ? $hasARNewStmt->fetchColumn() : false;
        if (!$hasAR && $hasARNew) {
            $rebuild->exec('ALTER TABLE admin_requests_new RENAME TO admin_requests');
        } elseif ($hasAR && $hasARNew) {
            $rebuild->exec('DROP TABLE admin_requests_new');
        }

        // ══════════════════════════════════════════════════════════
        // 2. Triggers + Rebuild sur $rebuild
        // ══════════════════════════════════════════════════════════
        $rebuild->exec("DROP TRIGGER IF EXISTS check_submissions_status_insert");
        $rebuild->exec("DROP TRIGGER IF EXISTS check_submissions_status_update");
        $rebuild->exec("DROP TRIGGER IF EXISTS check_tokens_action_insert");
        $rebuild->exec("DROP TRIGGER IF EXISTS check_tokens_action_update");
        $rebuild->exec("CREATE TRIGGER check_submissions_status_insert BEFORE INSERT ON submissions WHEN NEW.status NOT IN ('en_cours', 'valide', 'refuse', 'annule') BEGIN SELECT RAISE(ABORT, 'Invalid submissions.status'); END");
        $rebuild->exec("CREATE TRIGGER check_submissions_status_update BEFORE UPDATE OF status ON submissions WHEN NEW.status NOT IN ('en_cours', 'valide', 'refuse', 'annule') BEGIN SELECT RAISE(ABORT, 'Invalid submissions.status'); END");
        $rebuild->exec("CREATE TRIGGER check_tokens_action_insert BEFORE INSERT ON tokens WHEN NEW.action IS NOT NULL AND NEW.action NOT IN ('valider', 'refuser') BEGIN SELECT RAISE(ABORT, 'Invalid tokens.action'); END");
        $rebuild->exec("CREATE TRIGGER check_tokens_action_update BEFORE UPDATE OF action ON tokens WHEN NEW.action IS NOT NULL AND NEW.action NOT IN ('valider', 'refuser') BEGIN SELECT RAISE(ABORT, 'Invalid tokens.action'); END");

        $rebuild->exec('DROP TABLE IF EXISTS form_fields_new');
        $rebuild->exec('CREATE TABLE form_fields_new (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT \'text\', field_name TEXT NOT NULL, options TEXT, hint TEXT DEFAULT \'\', required INTEGER DEFAULT 0, ordre INTEGER DEFAULT 0, card_group TEXT DEFAULT \'Général\', filled_by TEXT DEFAULT \'demandeur\' CHECK (filled_by IN (\'demandeur\', \'validator\')), validator_step TEXT DEFAULT \'\', visibility TEXT DEFAULT \'all\' CHECK (visibility IN (\'all\', \'owner_only\')), condition TEXT DEFAULT \'\', FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)');
        $rebuild->exec('INSERT INTO form_fields_new SELECT * FROM form_fields');
        $rebuild->exec('DROP TABLE form_fields');
        $rebuild->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id, filled_by)');

        $rebuild->exec('DROP TABLE IF EXISTS admin_requests_new');
        $rebuild->exec('CREATE TABLE admin_requests_new (id TEXT PRIMARY KEY NOT NULL, email TEXT UNIQUE NOT NULL, requested_at DATETIME DEFAULT CURRENT_TIMESTAMP, status TEXT NOT NULL DEFAULT \'pending\' CHECK (status IN (\'pending\', \'approved\', \'rejected\')), token TEXT UNIQUE NOT NULL, reviewed_at DATETIME, reviewed_by TEXT)');
        $rebuild->exec('INSERT INTO admin_requests_new SELECT * FROM admin_requests');
        $rebuild->exec('DROP TABLE admin_requests');
        $rebuild->exec('ALTER TABLE admin_requests_new RENAME TO admin_requests');

        $rebuild->exec('PRAGMA foreign_keys = ON');
        $fkStmt = $rebuild->query('PRAGMA foreign_key_check');
        $fkErrors = $fkStmt !== false ? $fkStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $rebuild->exec("INSERT INTO schema_version (version, applied_at) VALUES (30, datetime('now'))");
        $rebuild = null;

        if (!empty($fkErrors)) {
            throw new \RuntimeException('v30: FK integrity broken: ' . json_encode($fkErrors));
        }

        return 30;
    } catch (\PDOException $e) {
        error_log("Migration v30 failed: " . $e->getMessage());
        return $current_version;
    }
}
