<?php
declare(strict_types=1);

use App\Core\App;

require_once __DIR__ . '/seed_default_forms.php';

/**
 * Initial schema creation — tables + default admin.
 *
 * Exécuté à chaque appel de db_migrate() (idempotent grâce à CREATE TABLE IF NOT EXISTS).
 * Crée toutes les tables de l'application si elles n'existent pas.
 *
 * @package Migrations
 */

/**
 * Crée le schéma initial de la base (toutes les tables) et procède au seeding
 * par défaut (admin, formulaires, paramètres).
 *
 * Exécuté à chaque appel de db_migrate() — idempotent via CREATE TABLE IF NOT EXISTS
 * et INSERT OR IGNORE / checks COUNT(*) préalables.
 *
 * @param PDO   $pdo         Connexion SQLite
 * @param bool  $seed_needed Passé par référence — toujours false (conservé pour compat)
 * @return int La version courante du schéma (0 si DB vierge, ou la version déjà appliquée)
 */
function apply_schema_initial(PDO $pdo, bool &$seed_needed = false): int {
    // Activer le mode WAL pour améliorer la concurrence
    $pdo->exec('PRAGMA journal_mode=WAL');

    // ── Schema versioning ─────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $current_version = (int)_dbm_q($pdo, "SELECT MAX(version) FROM schema_version")->fetchColumn();
    } catch (PDOException $e) {
        $current_version = 0;
    }

    // Création des tables avec CREATE TABLE IF NOT EXISTS
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS forms (
            id TEXT PRIMARY KEY NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            actif INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deadline_field TEXT DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS steps (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            ordre INTEGER NOT NULL,
            actif INTEGER DEFAULT 1,
            `condition` TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");
    // v19 : colonne `condition` (JSON) pour branches conditionnelles.
    // Mot-clé SQL réservé → entouré de backticks dans le DDL.
    // Vide ou null = pas de condition = l'étape s'exécute toujours (rétrocompat).

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS step_recipients (
            id TEXT PRIMARY KEY NOT NULL,
            step_id TEXT NOT NULL,
            email TEXT NOT NULL,
            FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submissions (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            data TEXT NOT NULL, -- JSON
            submitted_by TEXT NOT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            status TEXT DEFAULT 'en_cours',
            admin_comment TEXT DEFAULT '', -- v22 : annotation libre admin/owner
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tokens (
            id TEXT PRIMARY KEY NOT NULL,
            submission_id TEXT NOT NULL,
            step_id TEXT NOT NULL,
            email TEXT NOT NULL,
            token TEXT UNIQUE NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME,
            relance_at DATETIME,
            expires_at DATETIME,
            relance_count INTEGER DEFAULT 0,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id TEXT PRIMARY KEY NOT NULL,
            email TEXT UNIQUE NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_requests (
            id TEXT PRIMARY KEY NOT NULL,
            email TEXT UNIQUE NOT NULL,
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL DEFAULT 'pending',
            token TEXT UNIQUE NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_by TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS form_fields (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text',
            field_name TEXT NOT NULL,
            options TEXT,
            hint TEXT DEFAULT '',
            required INTEGER DEFAULT 0,
            ordre INTEGER DEFAULT 0,
            card_group TEXT DEFAULT 'Général',
            filled_by TEXT DEFAULT 'demandeur',
            validator_step TEXT DEFAULT '',
            visibility TEXT DEFAULT 'all',
            condition TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");

    // Table d'audit log — tracabilite de toutes les actions admin
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id TEXT PRIMARY KEY NOT NULL,
            action TEXT NOT NULL,
            target TEXT,
            detail TEXT,
            actor TEXT NOT NULL,
            ip TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Table des regles d'alerte — alertes parametrables avant deadline
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS alert_rules (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            days_before INTEGER NOT NULL DEFAULT 5,
            condition_type TEXT NOT NULL DEFAULT 'steps_incomplete',
            notify_who TEXT NOT NULL DEFAULT 'admin',
            label TEXT NOT NULL,
            actif INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");

    // Table de log des alertes envoyees — eviter les doublons
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS alert_log (
            id TEXT PRIMARY KEY NOT NULL,
            rule_id TEXT NOT NULL,
            submission_id TEXT NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            message TEXT,
            FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )
    ");

    // Table des pieces jointes — fichiers uploades avec les soumissions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attachments (
            id TEXT PRIMARY KEY NOT NULL,
            submission_id TEXT NOT NULL,
            field_name TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            file_data BLOB,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )
    ");

    // Table des delegations — transfert de validation a un autre validateur
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delegations (
            id TEXT PRIMARY KEY NOT NULL,
            token_id TEXT NOT NULL,
            from_email TEXT NOT NULL,
            to_email TEXT NOT NULL,
            reason TEXT,
            delegated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            new_token_id TEXT,
            FOREIGN KEY (token_id) REFERENCES tokens(id) ON DELETE CASCADE
        )
    ");

    // Table des proprietaires de formulaire — qui peut voir le tableau de suivi
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS form_owners (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            email TEXT NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(form_id, email),
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");

    // Table submission_validator_data (v13/v14 — champs validateur)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submission_validator_data (
            id TEXT PRIMARY KEY NOT NULL,
            submission_id TEXT NOT NULL,
            field_name TEXT NOT NULL,
            field_label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text',
            value TEXT,
            filled_by TEXT NOT NULL DEFAULT 'validator',
            filled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            step_id TEXT,
            step_label TEXT,
            filled_by_email TEXT,
            token_id TEXT,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_svd_sub_field ON submission_validator_data(submission_id, field_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_svds_submission ON submission_validator_data(submission_id)");

    // Table lazy_cron (v8)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lazy_cron (
            task_key TEXT PRIMARY KEY,
            last_run DATETIME NOT NULL,
            run_count INTEGER DEFAULT 0
        )
    ");

    // ── Seeding admin par défaut ──
    $seed_needed = false;
    try {
        $count_stmt = _dbm_q($pdo, "SELECT COUNT(*) FROM admins");
        if ($count_stmt->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, ?)")
                ->execute([generate_uuid(), App::auth()->getAdminEmail(), date('Y-m-d H:i:s')]);
        }
    } catch (\Throwable $e) {
        // Silencieux — App::auth() peut ne pas être disponible lors des tests
    }

    // ── Seed formulaires par défaut ──
    try {
        seed_default_forms($pdo);
    } catch (\Throwable $e) {
        // Silencieux
    }

    return $current_version;
}
