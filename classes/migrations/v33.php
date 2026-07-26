<?php
declare(strict_types=1);

/**
 * Migration v33: Durcissement SQL — CHECK sur colonnes enum-like restantes.
 *
 * Audit CTO 2026-07-26 a identifié 8 colonnes enum-like sans CHECK.
 * La règle AGENTS.md #8 impose : 'Pour toute colonne à valeurs limitées
 * (statut, type, enum-like), ajouter une contrainte CHECK en base en
 * plus de la validation PHP.'
 *
 * Colonnes durcies dans cette migration :
 *
 * 1. forms.actif                     → CHECK (actif IN (0, 1))
 * 2. steps.actif                     → CHECK (actif IN (0, 1))
 * 3. form_fields.required            → CHECK (required IN (0, 1))
 * 4. alert_rules.actif               → CHECK (actif IN (0, 1))
 * 5. alert_rules.days_before         → CHECK (days_before > 0)
 * 6. alert_rules.condition_type      → CHECK (condition_type = 'steps_incomplete')
 *    (seule valeur utilisée en pratique, domaine verrouillé)
 * 7. attachments.file_size           → CHECK (file_size >= 0)
 * 8. tokens.relance_count            → CHECK (relance_count >= 0)
 * 9. submissions.rgpd_consent        → CHECK (rgpd_consent IN (0, 1) OR rgpd_consent IS NULL)
 *
 * Bonus : ajout de FOREIGN KEY sur delegations.new_token_id → tokens(id)
 * (column précédemment sans FK, on garde ON DELETE SET NULL car la cible
 * peut être supprimée sans casser la traçabilité de la délégation).
 *
 * Toutes les tables sont reconstruites via le pattern v30/v31 (DROP +
 * CREATE + INSERT + RENAME), avec libération explicite des PDOStatement
 * avant chaque DDL (règle AGENTS.md SQLITE_LOCKED).
 *
 * @package Migrations
 */

function apply_migration_v33(PDO $pdo, int $current_version): int {
    $needs_v33 = ($current_version < 33) || ($current_version >= 900);
    if (!$needs_v33) {
        return $current_version;
    }

    try {
        $v33_stmt = $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 33");
        if ($v33_stmt === false) {
            throw new \RuntimeException('v33: COUNT query failed');
        }
        $v33_done = (int) $v33_stmt->fetchColumn();
        // CS-06 : libérer le statement avant le prochain DDL
        $v33_stmt = null;
        if ($v33_done > 0) {
            return max($current_version, 33);
        }

        $dbListStmt = $pdo->query('PRAGMA database_list');
        if ($dbListStmt === false) {
            throw new \RuntimeException('v33: PRAGMA database_list failed');
        }
        $dbPath = $dbListStmt->fetchColumn(2);
        $dbListStmt = null;

        $rebuild = new PDO('sqlite:' . $dbPath);
        $rebuild->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rebuild->exec('PRAGMA busy_timeout = 10000');
        $rebuild->exec('PRAGMA foreign_keys = OFF');

        // ── Self-healing : nettoyer les _new orphelines d'une panne précédente ──
        foreach (['forms', 'steps', 'form_fields', 'alert_rules', 'attachments', 'tokens', 'submissions', 'delegations'] as $table) {
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

        // ── 1. Rebuild forms (CHECK sur actif) ──
        $rebuild->exec('DROP TABLE IF EXISTS forms_new');
        $rebuild->exec("CREATE TABLE forms_new (
            id TEXT PRIMARY KEY NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            actif INTEGER DEFAULT 1 CHECK (actif IN (0, 1)),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deadline_field TEXT DEFAULT ''
        )");
        $rebuild->exec('INSERT INTO forms_new SELECT * FROM forms');
        $rebuild->exec('DROP TABLE forms');
        $rebuild->exec('ALTER TABLE forms_new RENAME TO forms');

        // ── 2. Rebuild steps (CHECK sur actif) ──
        $rebuild->exec('DROP TABLE IF EXISTS steps_new');
        $rebuild->exec("CREATE TABLE steps_new (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            ordre INTEGER NOT NULL,
            actif INTEGER DEFAULT 1 CHECK (actif IN (0, 1)),
            `condition` TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO steps_new SELECT * FROM steps');
        $rebuild->exec('DROP TABLE steps');
        $rebuild->exec('ALTER TABLE steps_new RENAME TO steps');

        // ── 3. Rebuild form_fields (CHECK sur required) ──
        // Note : field_type et filled_by/visibility déjà durcis en v30/v31
        $rebuild->exec('DROP TABLE IF EXISTS form_fields_new');
        $rebuild->exec("CREATE TABLE form_fields_new (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text' CHECK (field_type IN ('text', 'email', 'date', 'select', 'checkbox', 'textarea', 'file')),
            field_name TEXT NOT NULL,
            options TEXT,
            hint TEXT DEFAULT '',
            required INTEGER DEFAULT 0 CHECK (required IN (0, 1)),
            ordre INTEGER DEFAULT 0,
            card_group TEXT DEFAULT 'Général',
            filled_by TEXT DEFAULT 'demandeur' CHECK (filled_by IN ('demandeur', 'validator')),
            validator_step TEXT DEFAULT '',
            visibility TEXT DEFAULT 'all' CHECK (visibility IN ('all', 'owner_only')),
            `condition` TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO form_fields_new SELECT * FROM form_fields');
        $rebuild->exec('DROP TABLE form_fields');
        $rebuild->exec('ALTER TABLE form_fields_new RENAME TO form_fields');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_form ON form_fields(form_id)');
        $rebuild->exec('CREATE INDEX IF NOT EXISTS idx_ff_filled_by ON form_fields(form_id, filled_by)');

        // ── 4 & 5 & 6. Rebuild alert_rules (CHECK sur actif, days_before, condition_type) ──
        $rebuild->exec('DROP TABLE IF EXISTS alert_rules_new');
        $rebuild->exec("CREATE TABLE alert_rules_new (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            days_before INTEGER NOT NULL DEFAULT 5 CHECK (days_before > 0),
            condition_type TEXT NOT NULL DEFAULT 'steps_incomplete' CHECK (condition_type = 'steps_incomplete'),
            notify_who TEXT NOT NULL DEFAULT 'admin',
            label TEXT NOT NULL,
            actif INTEGER DEFAULT 1 CHECK (actif IN (0, 1)),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO alert_rules_new SELECT * FROM alert_rules');
        $rebuild->exec('DROP TABLE alert_rules');
        $rebuild->exec('ALTER TABLE alert_rules_new RENAME TO alert_rules');

        // ── 7. Rebuild attachments (CHECK sur file_size >= 0) ──
        $rebuild->exec('DROP TABLE IF EXISTS attachments_new');
        $rebuild->exec("CREATE TABLE attachments_new (
            id TEXT PRIMARY KEY NOT NULL,
            submission_id TEXT NOT NULL,
            field_name TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0 CHECK (file_size >= 0),
            file_data BLOB,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO attachments_new SELECT * FROM attachments');
        $rebuild->exec('DROP TABLE attachments');
        $rebuild->exec('ALTER TABLE attachments_new RENAME TO attachments');

        // ── 8. Rebuild tokens (CHECK sur relance_count >= 0) ──
        $rebuild->exec('DROP TABLE IF EXISTS tokens_new');
        $rebuild->exec("CREATE TABLE tokens_new (
            id TEXT PRIMARY KEY NOT NULL,
            submission_id TEXT NOT NULL,
            step_id TEXT NOT NULL,
            email TEXT NOT NULL,
            token TEXT UNIQUE NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME,
            relance_at DATETIME,
            expires_at DATETIME,
            relance_count INTEGER DEFAULT 0 CHECK (relance_count >= 0),
            invalidated_at DATETIME,
            action TEXT,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO tokens_new SELECT id, submission_id, step_id, email, token, sent_at, done_at, relance_at, expires_at, relance_count, invalidated_at, action FROM tokens');
        $rebuild->exec('DROP TABLE tokens');
        $rebuild->exec('ALTER TABLE tokens_new RENAME TO tokens');
        // Recréer l'index unique partiel v27 (perdu par DROP TABLE)
        $rebuild->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tokens_active_per_step_email
            ON tokens(submission_id, step_id, email)
            WHERE done_at IS NULL");

        // ── 9. Rebuild submissions (CHECK sur rgpd_consent) ──
        // rgpd_consent peut être NULL (soumissions pré-RGPD) ou 0/1
        $rebuild->exec('DROP TABLE IF EXISTS submissions_new');
        $rebuild->exec("CREATE TABLE submissions_new (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            data TEXT NOT NULL,
            submitted_by TEXT NOT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            status TEXT DEFAULT 'en_cours' CHECK (status IN ('en_cours', 'valide', 'refuse', 'annule')),
            admin_comment TEXT DEFAULT '',
            rgpd_consent INTEGER CHECK (rgpd_consent IN (0, 1) OR rgpd_consent IS NULL),
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )");
        $rebuild->exec('INSERT INTO submissions_new SELECT id, form_id, data, submitted_by, submitted_at, closed_at, status, admin_comment, rgpd_consent FROM submissions');
        $rebuild->exec('DROP TABLE submissions');
        $rebuild->exec('ALTER TABLE submissions_new RENAME TO submissions');
        // Recréer l'index unique partiel v32 (perdu par DROP TABLE)
        $rebuild->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_active_per_form_user
            ON submissions(form_id, submitted_by)
            WHERE status = 'en_cours' AND closed_at IS NULL");

        // ── Bonus : Rebuild delegations (FK sur new_token_id) ──
        $rebuild->exec('DROP TABLE IF EXISTS delegations_new');
        $rebuild->exec("CREATE TABLE delegations_new (
            id TEXT PRIMARY KEY NOT NULL,
            token_id TEXT NOT NULL,
            from_email TEXT NOT NULL,
            to_email TEXT NOT NULL,
            reason TEXT,
            delegated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            new_token_id TEXT,
            FOREIGN KEY (token_id) REFERENCES tokens(id) ON DELETE CASCADE,
            FOREIGN KEY (new_token_id) REFERENCES tokens(id) ON DELETE SET NULL
        )");
        $rebuild->exec('INSERT INTO delegations_new SELECT * FROM delegations');
        $rebuild->exec('DROP TABLE delegations');
        $rebuild->exec('ALTER TABLE delegations_new RENAME TO delegations');

        // ── FK integrity check ──
        $rebuild->exec('PRAGMA foreign_keys = ON');
        $fkStmt = $rebuild->query('PRAGMA foreign_key_check');
        $fkErrors = $fkStmt !== false ? $fkStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $fkStmt = null;

        $rebuild->exec("INSERT INTO schema_version (version, applied_at) VALUES (33, datetime('now'))");
        $rebuild = null;

        if ($fkErrors !== []) {
            throw new \RuntimeException('v33: FK integrity broken: ' . json_encode($fkErrors));
        }

        return 33;
    } catch (\PDOException $e) {
        error_log("Migration v33 failed: " . $e->getMessage());
        return $current_version;
    }
}
