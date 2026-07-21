<?php
declare(strict_types=1);

/**
 * Migration v31: Contraintes CHECK sur 4 colonnes enum supplémentaires.
 *
 * Ajoute des contraintes CHECK via rebuild de table (SQLite ne supporte pas
 * ALTER TABLE ADD CONSTRAINT) :
 *   - form_fields.field_type                     → 'text'|'email'|'date'|'select'|'checkbox'|'textarea'|'file'
 *     (liste de référence : FormJsonValidator::\$valid_field_types)
 *   - submission_validator_data.field_type        → même liste
 *   - submission_validator_data.filled_by         → 'demandeur'|'validator' (même domaine que form_fields.filled_by)
 *   - mail_log.status                             → 'sent'|'blocked'|'dry_run'|'error' (MailService::sendDetailed())
 *
 * form_fields avait déjà été reconstruite en v30 (filled_by, visibility) —
 * cette migration la reconstruit une seconde fois pour ajouter field_type.
 *
 * IMPORTANT : libérer TOUS les PDOStatement avant le DDL de rebuild (voir
 * AGENTS.md — SQLITE_LOCKED sur DROP TABLE si un statement reste ouvert).
 *
 * @package Migrations
 */

function apply_migration_v31(PDO $pdo, int $current_version): int {
    $needs_v31 = ($current_version < 31) || ($current_version >= 900);
    if (!$needs_v31) {
        return $current_version;
    }

    try {
        $v31_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 31");
        if ($v31_stmt === false) {
            throw new \RuntimeException('v31: COUNT query failed');
        }
        $v31_done = (int) $v31_stmt->fetchColumn();
        $v31_stmt = null;
        if ($v31_done > 0) {
            return max($current_version, 31);
        }

        $dbListStmt = $pdo->query('PRAGMA database_list');
        if ($dbListStmt === false) {
            throw new \RuntimeException('v31: PRAGMA database_list failed');
        }
        $dbPath = $dbListStmt->fetchColumn(2);
        $dbListStmt = null;

        $rebuild = new PDO('sqlite:' . $dbPath);
        $rebuild->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rebuild->exec('PRAGMA busy_timeout = 10000');
        $rebuild->exec('PRAGMA foreign_keys = OFF');

        // ── Self-healing : nettoyer les _new orphelines d'une panne précédente ──
        foreach (['form_fields', 'submission_validator_data', 'mail_log'] as $table) {
            $hasOldStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
            $hasOld = $hasOldStmt !== false && $hasOldStmt->fetchColumn() !== false;
            $hasOldStmt = null;

            $hasNewStmt = $rebuild->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}_new'");
            $hasNew = $hasNewStmt !== false && $hasNewStmt->fetchColumn() !== false;
            $hasNewStmt = null;

            if (!$hasOld && $hasNew) {
                $rebuild->exec("ALTER TABLE {$table}_new RENAME TO {$table}");
            } elseif ($hasOld && $hasNew) {
                $rebuild->exec("DROP TABLE {$table}_new");
            }
        }

        // ── Rebuild form_fields : ajoute CHECK sur field_type (garde filled_by/visibility de v30) ──
        $rebuild->exec('DROP TABLE IF EXISTS form_fields_new');
        $rebuild->exec("CREATE TABLE form_fields_new (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file')),
            field_name TEXT NOT NULL,
            options TEXT,
            hint TEXT DEFAULT '',
            required INTEGER DEFAULT 0,
            ordre INTEGER DEFAULT 0,
            card_group TEXT DEFAULT 'Général',
            filled_by TEXT DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur', 'validator')),
            validator_step TEXT DEFAULT '',
            visibility TEXT DEFAULT 'all' CHECK (visibility IN ('all', 'owner_only')),
            condition TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO form_fields_new SELECT * FROM form_fields');
        $rebuild->exec('DROP TABLE form_fields');
        $rebuild->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id, filled_by)');

        // ── Rebuild submission_validator_data : CHECK sur field_type + filled_by ──
        $rebuild->exec('DROP TABLE IF EXISTS submission_validator_data_new');
        $rebuild->exec("CREATE TABLE submission_validator_data_new (
            id TEXT PRIMARY KEY NOT NULL,
            submission_id TEXT NOT NULL,
            field_name TEXT NOT NULL,
            field_label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file')),
            value TEXT,
            filled_by TEXT NOT NULL DEFAULT 'validator' CHECK (filled_by IN ('demandeur', 'validator')),
            filled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            step_id TEXT,
            step_label TEXT,
            filled_by_email TEXT,
            token_id TEXT,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO submission_validator_data_new SELECT * FROM submission_validator_data');
        $rebuild->exec('DROP TABLE submission_validator_data');
        $rebuild->exec('ALTER TABLE submission_validator_data_new RENAME TO submission_validator_data');
        $rebuild->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_svd_sub_field ON submission_validator_data(submission_id, field_name)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_svds_submission ON submission_validator_data(submission_id)');

        // ── Rebuild mail_log : CHECK sur status ──
        $rebuild->exec('DROP TABLE IF EXISTS mail_log_new');
        $rebuild->exec("CREATE TABLE mail_log_new (
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('sent', 'blocked', 'dry_run', 'error')),
            error_message TEXT DEFAULT '',
            smtp_log TEXT DEFAULT '',
            actor TEXT DEFAULT '',
            ip TEXT DEFAULT ''
        )");
        $rebuild->exec('INSERT INTO mail_log_new SELECT * FROM mail_log');
        $rebuild->exec('DROP TABLE mail_log');
        $rebuild->exec('ALTER TABLE mail_log_new RENAME TO mail_log');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_created_at ON mail_log(created_at DESC)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_recipient ON mail_log(recipient)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_mail_log_status ON mail_log(status)');

        $rebuild->exec('PRAGMA foreign_keys = ON');
        $fkStmt = $rebuild->query('PRAGMA foreign_key_check');
        $fkErrors = $fkStmt !== false ? $fkStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $fkStmt = null;

        $rebuild->exec("INSERT INTO schema_version (version, applied_at) VALUES (31, datetime('now'))");
        $rebuild = null;

        if ($fkErrors !== []) {
            throw new \RuntimeException('v31: FK integrity broken: ' . json_encode($fkErrors));
        }

        return 31;
    } catch (\PDOException $e) {
        error_log("Migration v31 failed: " . $e->getMessage());
        return $current_version;
    }
}
