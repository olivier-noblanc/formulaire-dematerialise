<?php
declare(strict_types=1);

use App\Core\App;

require_once __DIR__ . '/seed_default_forms.php';
require_once __DIR__ . '/post_migration.php';

/** Create the complete v42 schema and seed a fresh installation atomically. */
function apply_schema_initial(PDO $pdo): int
{
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $version = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_version')->fetchColumn();
    if ($version >= 42 && $version < 900) return $version;

    $pdo->beginTransaction();
    try {
        $ddl = [
            "CREATE TABLE IF NOT EXISTS forms (id TEXT PRIMARY KEY NOT NULL, slug TEXT UNIQUE NOT NULL, label TEXT NOT NULL, description TEXT, actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0,1)), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, deadline_field TEXT DEFAULT '')",
            "CREATE TABLE IF NOT EXISTS steps (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, label TEXT NOT NULL, ordre INTEGER NOT NULL, actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0,1)), `condition` TEXT DEFAULT '', FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS step_recipients (id TEXT PRIMARY KEY NOT NULL, step_id TEXT NOT NULL, email TEXT NOT NULL, FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS submissions (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, data TEXT NOT NULL, submitted_by TEXT NOT NULL, submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME, status TEXT DEFAULT 'en_cours' CHECK (status IN ('en_cours','valide','refuse','annule')), admin_comment TEXT DEFAULT '', rgpd_consent INTEGER CHECK (rgpd_consent IN (0,1) OR rgpd_consent IS NULL), FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS tokens (id TEXT PRIMARY KEY NOT NULL, submission_id TEXT NOT NULL, step_id TEXT NOT NULL, email TEXT NOT NULL, token TEXT UNIQUE NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, done_at DATETIME, relance_at DATETIME, expires_at DATETIME, relance_count INTEGER NOT NULL DEFAULT 0 CHECK (relance_count >= 0), action TEXT, invalidated_at DATETIME, FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE, FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS admins (id TEXT PRIMARY KEY NOT NULL, email TEXT UNIQUE NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS admin_requests (id TEXT PRIMARY KEY NOT NULL, email TEXT UNIQUE NOT NULL, requested_at DATETIME DEFAULT CURRENT_TIMESTAMP, status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')), token TEXT UNIQUE NOT NULL, reviewed_at DATETIME, reviewed_by TEXT)",
            "CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_by TEXT)",
            "CREATE TABLE IF NOT EXISTS form_fields (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text','email','date','select','checkbox','textarea','file')), field_name TEXT NOT NULL, options TEXT, hint TEXT DEFAULT '', required INTEGER NOT NULL DEFAULT 0 CHECK (required IN (0,1)), ordre INTEGER DEFAULT 0, card_group TEXT DEFAULT 'Général', filled_by TEXT DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur','validator')), validator_step TEXT DEFAULT '', visibility TEXT DEFAULT 'all' CHECK (visibility IN ('all','owner_only')), `condition` TEXT DEFAULT '', FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS audit_log (id TEXT PRIMARY KEY NOT NULL, action TEXT NOT NULL, target TEXT, detail TEXT, actor TEXT NOT NULL, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS alert_rules (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, days_before INTEGER NOT NULL DEFAULT 5 CHECK (days_before > 0), condition_type TEXT NOT NULL DEFAULT 'steps_incomplete' CHECK (condition_type = 'steps_incomplete'), notify_who TEXT NOT NULL DEFAULT 'admin', label TEXT NOT NULL, actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0,1)), created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS alert_log (id TEXT PRIMARY KEY NOT NULL, rule_id TEXT NOT NULL, submission_id TEXT NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, message TEXT, FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE, FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS attachments (id TEXT PRIMARY KEY NOT NULL, submission_id TEXT NOT NULL, field_name TEXT NOT NULL, original_name TEXT NOT NULL, stored_name TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL DEFAULT 0 CHECK (file_size >= 0), file_data BLOB, uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS delegations (id TEXT PRIMARY KEY NOT NULL, token_id TEXT NOT NULL, from_email TEXT NOT NULL, to_email TEXT NOT NULL, reason TEXT, delegated_at DATETIME DEFAULT CURRENT_TIMESTAMP, new_token_id TEXT, FOREIGN KEY (token_id) REFERENCES tokens(id) ON DELETE CASCADE, FOREIGN KEY (new_token_id) REFERENCES tokens(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS form_owners (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, email TEXT NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(form_id,email), FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS submission_validator_data (id TEXT PRIMARY KEY NOT NULL, submission_id TEXT NOT NULL, field_name TEXT NOT NULL, field_label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text','email','date','select','checkbox','textarea','file')), value TEXT, filled_by TEXT NOT NULL DEFAULT 'validator' CHECK (filled_by IN ('demandeur','validator')), filled_at DATETIME DEFAULT CURRENT_TIMESTAMP, step_id TEXT, step_label TEXT, filled_by_email TEXT, token_id TEXT, FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS lazy_cron (task_key TEXT PRIMARY KEY, last_run DATETIME NOT NULL, run_count INTEGER DEFAULT 0)",
            "CREATE TABLE IF NOT EXISTS persona_tokens (id TEXT PRIMARY KEY, token TEXT NOT NULL UNIQUE, admin_email TEXT NOT NULL, target_email TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')), expires_at TEXT NOT NULL, revoked_at TEXT)",
            "CREATE TABLE IF NOT EXISTS mail_log (id TEXT PRIMARY KEY, created_at TEXT NOT NULL, recipient TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, status TEXT NOT NULL CHECK (status IN ('pending','sent','error','failed','blocked','dry_run')), error_message TEXT DEFAULT '', smtp_log TEXT DEFAULT '', attempts INTEGER NOT NULL DEFAULT 0, next_retry_at TEXT, manual_replay_count INTEGER NOT NULL DEFAULT 0, actor TEXT DEFAULT '', ip TEXT DEFAULT '')",
        ];
        foreach ($ddl as $sql) $pdo->exec($sql);
        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_tokens_active_per_step_email ON tokens(submission_id,step_id,email) WHERE done_at IS NULL AND invalidated_at IS NULL',
            'CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)',
            'CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id,filled_by)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_svd_sub_field ON submission_validator_data(submission_id,field_name)',
            'CREATE INDEX IF NOT EXISTS idx_svds_submission ON submission_validator_data(submission_id)',
            'CREATE INDEX IF NOT EXISTS idx_persona_tokens_token ON persona_tokens(token)',
            'CREATE INDEX IF NOT EXISTS idx_persona_tokens_admin ON persona_tokens(admin_email)',
            'CREATE INDEX IF NOT EXISTS idx_mail_log_created_at ON mail_log(created_at DESC)',
            'CREATE INDEX IF NOT EXISTS idx_mail_log_recipient ON mail_log(recipient)',
            'CREATE INDEX IF NOT EXISTS idx_mail_log_status ON mail_log(status)',
            'CREATE INDEX IF NOT EXISTS idx_mail_log_next_retry ON mail_log(status,next_retry_at)',
        ] as $sql) $pdo->exec($sql);
        $status = "'en_cours','valide','refuse','annule'";
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS check_submissions_status_insert BEFORE INSERT ON submissions WHEN NEW.status IS NULL OR NEW.status NOT IN ($status) BEGIN SELECT RAISE(ABORT,'Invalid submissions.status'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS check_submissions_status_update BEFORE UPDATE OF status ON submissions WHEN NEW.status IS NULL OR NEW.status NOT IN ($status) BEGIN SELECT RAISE(ABORT,'Invalid submissions.status'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS check_tokens_action_insert BEFORE INSERT ON tokens WHEN NEW.action IS NOT NULL AND NEW.action NOT IN ('valider','refuser','annule') BEGIN SELECT RAISE(ABORT,'Invalid tokens.action'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS check_tokens_action_update BEFORE UPDATE OF action ON tokens WHEN NEW.action IS NOT NULL AND NEW.action NOT IN ('valider','refuser','annule') BEGIN SELECT RAISE(ABORT,'Invalid tokens.action'); END");
        $adminEmail = '';
        try { $adminEmail = App::auth()->getAdminEmail(); } catch (Throwable) { $adminEmail = defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS['admin_email'] : ''; }
        if ($adminEmail !== '') $pdo->prepare('INSERT OR IGNORE INTO admins (id,email) VALUES (?,?)')->execute([generate_uuid(), $adminEmail]);
        seed_default_forms($pdo);
        apply_post_migration_fixes($pdo);
        if ($pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC) !== []) throw new RuntimeException('v42: foreign key check failed');
        $pdo->exec("INSERT OR IGNORE INTO schema_version (version, applied_at) VALUES (42, datetime('now'))");
        $pdo->commit();
        return 42;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
