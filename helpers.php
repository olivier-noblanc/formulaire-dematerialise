<?php
require_once __DIR__ . '/config.php';
session_start();

// ── TEST MODE ──────────────────────────────────────────────────
// Activé par le header HTTP X-Test-Mode: 1
// Permet les tests automatisés via curl sans SMTP, sans CSRF, avec
// identification par header X-Test-User au lieu de AUTH_USER (IIS).
define('TEST_MODE', !empty($_SERVER['HTTP_X_TEST_MODE']));

// En mode test, supprimer les warnings PHP qui corrompent le JSON
if (TEST_MODE) {
    error_reporting(E_ERROR | E_PARSE);
    ini_set('display_errors', '0');
}

// File d'attente des mails interceptés en mode test (acces global)
$GLOBALS['_test_mails'] = [];

// Base de données test séparée pour ne pas polluer la vraie DB
if (TEST_MODE) {
    $test_db_path = __DIR__ . '/db/workflow_test.db';
    // Définir DB_PATH avant que config.php ne soit déjà chargé — on override via constante
    // Comme DB_PATH est déjà définie, on ne peut pas la redéfinir. On utilise un flag global.
    $GLOBALS['_test_db_path'] = $test_db_path;
}
// Tentative d'inclusion de vendor/autoload.php, mais ignorée si non présente
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

// ── UTILITAIRES ──────────────────────────────────────────────
function get_auth_user(): string {
    // Mode test : utiliser le header X-Test-User
    if (TEST_MODE) {
        $test_user = $_SERVER['HTTP_X_TEST_USER'] ?? '';
        if (!empty($test_user)) {
            // Si contient un @, c'est déjà un email
            if (strpos($test_user, '@') !== false) {
                return strtolower($test_user);
            }
            // Sinon, transformer en email DREETS
            return strtolower($test_user) . '@dreets.gouv.fr';
        }
        // Fallback : utilisateur test par défaut
        return 'test.agent@dreets.gouv.fr';
    }

    $auth_user = $_SERVER['AUTH_USER'] ?? '';
    if (empty($auth_user)) {
        http_response_code(401);
        die('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authentification requise — ' . h(get_app_name()) . '</title>
' . render_favicon() . '
<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{font-family:"Marianne",Arial,sans-serif;background:#f5f5fe;color:#1e1e1e}.bandeau{background:#003189;color:#fff;padding:.75rem 2rem;font-size:.85rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}.error-page{display:flex;min-height:calc(100vh - 120px);align-items:center;justify-content:center;padding:2rem 1rem}.error-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:3rem 2.5rem;max-width:560px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}.error-card .error-code{font-size:5rem;font-weight:900;line-height:1;margin-bottom:.25rem;letter-spacing:-2px;color:#003189}.error-card .error-illustration{margin-bottom:1.25rem}.error-card .error-illustration svg{width:100px;height:100px}.error-card h1{font-size:1.35rem;color:#1e1e1e;margin-bottom:.75rem;border:none;padding:0}.error-card .error-message{color:#555;font-size:.95rem;line-height:1.6;margin-bottom:1.25rem}.error-card .error-hint{font-size:.85rem;color:#666;background:#f5f5fe;border:1px solid #e0e0f0;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.5rem;text-align:left;line-height:1.55}.error-card .error-hint strong{color:#333;display:block;margin-bottom:.35rem}.error-card .error-stamp{margin-top:1.5rem;padding-top:1rem;border-top:1px solid #eee;font-size:.75rem;color:#aaa}</style></head><body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l\'Économie, de l\'Emploi, du Travail et des Solidarités
</div>
<div class="error-page">
  <div class="error-card">
    <div class="error-illustration"><svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#003189" stroke-width="5" fill="#e8eaf6"/><rect x="38" y="42" width="24" height="22" rx="3" fill="#003189"/><path d="M42 42V36 a8 8 0 0 1 16 0v6" stroke="#003189" stroke-width="3" fill="none" stroke-linecap="round"/><circle cx="50" cy="52" r="2.5" fill="#e8eaf6"/></svg></div>
    <div class="error-code">401</div>
    <h1>Authentification requise</h1>
    <p class="error-message">Cette application nécessite une authentification Windows (IIS) pour fonctionner.<br>La variable d\'environnement <strong>AUTH_USER</strong> n\'est pas disponible, ce qui indique que l\'authentification Windows n\'est pas configurée ou n\'a pas pu être établie.</p>
    <div class="error-hint">
      <strong>Que faire ?</strong>
      • Vérifiez que vous accédez à l\'application via le réseau interne DREETS.<br>
      • Vérifiez que l\'authentification Windows est activée dans IIS (Anonymous Authentication doit être désactivé).<br>
      • Contactez votre administrateur réseau si le problème persiste.
    </div>
    <div class="error-stamp">' . h(get_app_name()) . '</div>
  </div>
</div>
</body></html>');
    }
    
    // Si l'utilisateur est au format DOMAINE\login, on extrait le login et on le transforme en email
    if (strpos($auth_user, '\\') !== false) {
        $parts = explode('\\', $auth_user);
        $login = end($parts);
        return strtolower($login) . '@dreets.gouv.fr';
    }
    
    // Sinon, on suppose que c'est déjà un email
    return strtolower($auth_user);
}

// Vérifie si l'utilisateur est administrateur
function is_admin_user(): bool {
    $auth_user = get_auth_user();
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
    $stmt->execute([$auth_user]);
    return $stmt->fetch() !== false;
}

// Vérifie si l'utilisateur est l'admin principal
function is_super_admin(): bool {
    $auth_user = get_auth_user();
    return $auth_user === get_admin_email();
}

// Récupère l'email de l'admin principal (depuis settings DB, fallback ADMIN_EMAIL)
function get_admin_email(): string {
    try {
        $email = get_setting('admin_email', '');
        if (!empty($email)) return $email;
    } catch (\Throwable $e) {}
    return defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';
}

// Vérifie si l'utilisateur est propriétaire d'un formulaire donné
function is_form_owner(string $form_id, ?string $email = null): bool {
    if ($email === null) {
        $email = get_auth_user();
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT 1 FROM form_owners WHERE form_id = ? AND email = ?");
    $stmt->execute([$form_id, $email]);
    return $stmt->fetch() !== false;
}

// Récupère la liste des propriétaires d'un formulaire
function get_form_owners(string $form_id): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id, email, added_at FROM form_owners WHERE form_id = ? ORDER BY email");
    $stmt->execute([$form_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupère les formulaires dont l'utilisateur est propriétaire
function get_owned_forms(?string $email = null): array {
    if ($email === null) {
        $email = get_auth_user();
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT f.id, f.slug, f.label, f.description FROM forms f INNER JOIN form_owners fo ON fo.form_id = f.id WHERE fo.email = ? AND f.actif = 1 ORDER BY f.label");
    $stmt->execute([$email]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── LAZY CRON ────────────────────────────────────────────────
// Pas de cron sur le serveur. Le premier utilisateur qui se connecte
// dans la journée/heure déclenche les tâches planifiées.

/**
 * Execute les tâches planifiées si nécessaire.
 * Appelé automatiquement à chaque connexion (depuis get_pdo).
 * 
 * Fréquences :
 * - daily  : alert_check (1x/jour)
 * - hourly : remind (1x/heure)
 */
function run_lazy_cron(PDO $pdo): void {
    static $running = false;
    if ($running) return; // Éviter la récursion
    $running = true;

    $now = (int) time();
    
    $tasks = [
        'remind'      => ['interval' => 3600,      'file' => __DIR__ . '/remind.php'],
        'alert_check' => ['interval' => 86400,     'file' => __DIR__ . '/alert_check.php'],
    ];
    
    try {
        foreach ($tasks as $key => $task) {
            // Vérifier la dernière exécution
            $stmt = $pdo->prepare("SELECT last_run FROM lazy_cron WHERE task_key = ?");
            $stmt->execute([$key]);
            $last_run = $stmt->fetchColumn();

            $should_run = false;
            if ($last_run === false || $last_run === null || $last_run === '') {
                $should_run = true;
            } else {
                $last_ts = strtotime($last_run);
                if ($last_ts === false) {
                    // Date invalide en base → on relance la tâche
                    $should_run = true;
                } elseif (($now - $last_ts) >= $task['interval']) {
                    $should_run = true;
                }
            }

            if ($should_run) {
                // Verrouillage : éviter les exécutions concurrentes
                try {
                    $pdo->prepare("INSERT OR REPLACE INTO lazy_cron (task_key, last_run, run_count) VALUES (?, ?, COALESCE((SELECT run_count FROM lazy_cron WHERE task_key = ?), 0) + 1)")
                        ->execute([$key, date('Y-m-d H:i:s', $now), $key]);
                } catch (PDOException $e) {
                    continue; // Un autre processus est en cours
                }

                // Exécuter la tâche dans un bloc try/catch pour ne pas casser la page
                try {
                    // Les scripts remind.php et alert_check.php sont conçus pour être
                    // appelés en CLI. On les inclut directement avec output buffering.
                    ob_start();
                    require $task['file'];
                    ob_end_clean();
                } catch (\Throwable $e) {
                    // Ne pas faire échouer la page utilisateur
                    error_log("Lazy cron error ({$key}): " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        // Sécurité globale : ne jamais laisser le cron casser get_pdo()
        error_log("Lazy cron fatal: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }
}

// ── CSRF ─────────────────────────────────────────────────────
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(generate_csrf_token()) . '">';
}

function verify_csrf(): bool {
    // Mode test : bypass CSRF
    if (TEST_MODE) return true;
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ── PDO ──────────────────────────────────────────────────────
function get_pdo(): PDO {
    static $pdo = null;
    static $pdo_test = null;

    // Mode test : utiliser la DB de test séparée
    if (TEST_MODE) {
        if ($pdo_test === null) {
            $test_db_path = $GLOBALS['_test_db_path'] ?? __DIR__ . '/db/workflow_test.db';
            $pdo_test = new PDO('sqlite:' . $test_db_path);
            $pdo_test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            db_migrate($pdo_test);
        }
        return $pdo_test;
    }

    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Appel à la migration de base de données au premier accès
        db_migrate($pdo);
        
        // Exécuter les tâches planifiées (lazy cron) au premier accès
        run_lazy_cron($pdo);
    }
    return $pdo;
}

/**
 * Genere un UUID v4 (RFC 4122 compliant)
 * Utilise random_bytes() pour la securite cryptographique
 */
function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Recupere un formulaire par son UUID
 */
function get_form_by_uuid(string $uuid): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$uuid]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    return $form ?: null;
}

/**
 * Vérifie et corrige automatiquement les tables qui ont encore un PK INTEGER.
 * S'exécute à chaque accès via db_migrate() — rapide car PRAGMA table_info
 * est instantané. Ne fait rien si le schéma est déjà correct.
 * 
 * Cette fonction est nécessaire car la migration v9 a pu échouer silencieusement
 * sur certaines bases (uuid manquant, contrainte FK, etc.) et se marquer comme
 * effectuée via INSERT OR IGNORE — empêchant toute re-exécution.
 */
function ensure_text_ids(PDO $pdo): void {
    // Schéma cible pour chaque table (uniquement les tables avec id TEXT PK)
    $schemas = [
        'forms' => "CREATE TABLE forms (
            id TEXT PRIMARY KEY NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            actif INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deadline_field TEXT DEFAULT ''
        )",
        'steps' => "CREATE TABLE steps (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            ordre INTEGER NOT NULL,
            actif INTEGER DEFAULT 1,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )",
        'step_recipients' => "CREATE TABLE step_recipients (
            id TEXT PRIMARY KEY NOT NULL,
            step_id TEXT NOT NULL,
            email TEXT NOT NULL,
            FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE
        )",
        'form_fields' => "CREATE TABLE form_fields (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text',
            field_name TEXT NOT NULL,
            options TEXT,
            required INTEGER DEFAULT 0,
            ordre INTEGER DEFAULT 0,
            card_group TEXT DEFAULT 'Général',
            hint TEXT DEFAULT '',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )",
        'admins' => "CREATE TABLE admins (
            id TEXT PRIMARY KEY NOT NULL,
            email TEXT UNIQUE NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'admin_requests' => "CREATE TABLE admin_requests (
            id TEXT PRIMARY KEY NOT NULL,
            email TEXT UNIQUE NOT NULL,
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT NOT NULL DEFAULT 'pending',
            token TEXT UNIQUE NOT NULL
        )",
        'audit_log' => "CREATE TABLE audit_log (
            id TEXT PRIMARY KEY NOT NULL,
            action TEXT NOT NULL,
            target TEXT,
            detail TEXT,
            actor TEXT NOT NULL,
            ip TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        'submissions' => "CREATE TABLE submissions (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            data TEXT NOT NULL,
            submitted_by TEXT NOT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME,
            status TEXT DEFAULT 'en_cours',
            rgpd_consent INTEGER DEFAULT 0,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )",
        'tokens' => "CREATE TABLE tokens (
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
        )",
        'alert_rules' => "CREATE TABLE alert_rules (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            days_before INTEGER NOT NULL DEFAULT 5,
            condition_type TEXT NOT NULL DEFAULT 'steps_incomplete',
            notify_who TEXT NOT NULL DEFAULT 'admin',
            label TEXT NOT NULL,
            actif INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )",
        'alert_log' => "CREATE TABLE alert_log (
            id TEXT PRIMARY KEY NOT NULL,
            rule_id TEXT NOT NULL,
            submission_id TEXT NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            message TEXT,
            FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )",
        'attachments' => "CREATE TABLE attachments (
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
        )",
        'delegations' => "CREATE TABLE delegations (
            id TEXT PRIMARY KEY NOT NULL,
            token_id TEXT NOT NULL,
            from_email TEXT NOT NULL,
            to_email TEXT NOT NULL,
            reason TEXT,
            delegated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            new_token_id TEXT,
            FOREIGN KEY (token_id) REFERENCES tokens(id) ON DELETE CASCADE
        )",
        'form_owners' => "CREATE TABLE form_owners (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            email TEXT NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(form_id, email),
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )",
        'rate_limits' => "CREATE TABLE rate_limits (
            id TEXT PRIMARY KEY NOT NULL,
            action_key TEXT NOT NULL,
            ip TEXT NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
    ];

    $fixed = 0;
    foreach ($schemas as $table_name => $create_sql) {
        try {
            $cols = $pdo->query("PRAGMA table_info({$table_name})")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            continue; // Table inexistante — pas notre problème
        }

        // Vérifier si le PK est INTEGER
        $has_int_pk = false;
        foreach ($cols as $col) {
            if ($col['pk'] == 1 && stripos($col['type'], 'INT') === 0) {
                $has_int_pk = true;
                break;
            }
        }

        if (!$has_int_pk) {
            continue; // Déjà en TEXT, rien à faire
        }

        // ── Correction nécessaire : re-créer la table avec id TEXT ──
        try {
            $pdo->exec("PRAGMA foreign_keys = OFF");

            // Colonnes communes entre l'ancienne table et la nouvelle définition
            $old_col_names = array_column($cols, 'name');
            // Extraire les noms de colonnes du CREATE (ignorer FOREIGN KEY, UNIQUE, CONSTRAINT, etc.)
            $new_col_names = [];
            if (preg_match_all('/^\s+(\w+)\s+(TEXT|INTEGER|DATETIME|BLOB|REAL)/im', $create_sql, $matches)) {
                $new_col_names = $matches[1];
            }
            $copy_cols = array_values(array_intersect($old_col_names, $new_col_names));
            $copy_cols_str = implode(', ', $copy_cols);

            // Créer la table temporaire avec le bon schéma
            $tmp = "__etid_tmp_{$table_name}";
            $pdo->exec("DROP TABLE IF EXISTS {$tmp}");
            $pdo->exec(str_replace("CREATE TABLE {$table_name}", "CREATE TABLE {$tmp}", $create_sql));

            // Copier les données
            if (!empty($copy_cols_str)) {
                $pdo->exec("INSERT INTO {$tmp} ({$copy_cols_str}) SELECT {$copy_cols_str} FROM {$table_name}");
            }

            // Remplacer l'ancienne table
            $pdo->exec("DROP TABLE {$table_name}");
            $pdo->exec("ALTER TABLE {$tmp} RENAME TO {$table_name}");

            $pdo->exec("PRAGMA foreign_keys = ON");

            $fixed++;
            error_log("ensure_text_ids: {$table_name} corrigé (id INTEGER → TEXT)");
        } catch (PDOException $e) {
            try { $pdo->exec("PRAGMA foreign_keys = ON"); } catch (PDOException $e2) {}
            error_log("ensure_text_ids ERREUR sur {$table_name}: " . $e->getMessage());
        }
    }

    if ($fixed > 0) {
        error_log("ensure_text_ids: {$fixed} table(s) corrigée(s)");
    }
}

/**
 * Migration automatique de la base de données
 * Crée les tables si elles n'existent pas et effectue les mises à jour nécessaires
 */
function db_migrate(PDO $pdo): void {
    // Activer le mode WAL pour améliorer la concurrence
    $pdo->exec('PRAGMA journal_mode=WAL');
    
    // ── Schema versioning ─────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (version INTEGER PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $current_version = (int)$pdo->query("SELECT MAX(version) FROM schema_version")->fetchColumn();
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
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS steps (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            label TEXT NOT NULL,
            ordre INTEGER NOT NULL,
            actif INTEGER DEFAULT 1,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");
    
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
    
    // Vérifier si la table admins est vide et insérer l'administrateur principal si nécessaire
    // ⚠️  Le seeding est encapsulé dans un try/catch car sur une base existante
    //     (avant migration v9), les colonnes id sont encore INTEGER et les UUIDs
    //     TEXT causent un "datatype mismatch". Le seeding sera re-tenté après
    //     les migrations versionnées via le flag $seed_needed.
    $seed_needed = false;
    try {
        $count_stmt = $pdo->query("SELECT COUNT(*) FROM admins");
        if ($count_stmt->fetchColumn() == 0) {
            $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, ?)")
                ->execute([generate_uuid(), get_admin_email(), date('Y-m-d H:i:s')]);
        }
    } catch (PDOException $e) {
        $seed_needed = true;
    }

    // Seed formulaire outboarding si la table forms est vide ou ne contient que l'onboarding
    // ⚠️  Encapsulé dans try/catch : si la base existe encore avec id INTEGER (avant v9),
    //     les INSERT UUID échouent en "datatype mismatch". Le seeding sera re-tenté
    //     après les migrations versionnées si $seed_needed = true.
    try {
    $ob_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'outboarding'")->fetchColumn();
    if ($ob_count == 0) {
        $outboarding_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$outboarding_id, 'outboarding', 'Départ agent', 'Formulaire de départ d\'un agent — restitution du matériel, cloture des accès et formalités de fin de contrat']);

        $outboarding_fields = [
            ['Identité de l\'agent',    'Nom',                                    'text',     'nom',                  null,                                                                                                   1, 1],
            ['Identité de l\'agent',    'Prénom',                                 'text',     'prenom',               null,                                                                                                   1, 2],
            ['Identité de l\'agent',    'Date de départ',                         'date',     'date_depart',          null,                                                                                                   1, 3],
            ['Identité de l\'agent',    'Motif de départ',                        'select',   'motif_depart',         '["Démission","Retraite","Mutation","Fin de contrat","Licenciement","Autre"]',                  1, 4],
            ['Identité de l\'agent',    'Service / Affectation',                  'text',     'affectation',          null,                                                                                                   1, 5],
            ['Informatique (IT)',       'Restitution poste informatique',         'checkbox', 'it_restitution_poste', null,                                                                                                   0, 6],
            ['Informatique (IT)',       'Restitution téléphone pro',              'checkbox', 'it_restitution_tel',   null,                                                                                                   0, 7],
            ['Informatique (IT)',       'Révocation accès RPVN',                  'checkbox', 'it_revoq_rpvn',        null,                                                                                                   0, 8],
            ['Informatique (IT)',       'Révocation accès applicatifs métier',    'checkbox', 'it_revoq_applicatifs', null,                                                                                                   0, 9],
            ['Informatique (IT)',       'Révocation compte de messagerie',        'checkbox', 'it_revoq_messagerie',  null,                                                                                                   0, 10],
            ['Informatique (IT)',       'Transfert boîte mail (destinataire)',    'text',     'it_transfert_mail',    null,                                                                                                   0, 11],
            ['Ressources Humaines',     'Solde de tout compte',                   'checkbox', 'rh_solde_compte',      null,                                                                                                   0, 12],
            ['Ressources Humaines',     'Attestation employeur',                  'checkbox', 'rh_attestation',       null,                                                                                                   0, 13],
            ['Ressources Humaines',     'Certificat de travail',                  'checkbox', 'rh_certificat',        null,                                                                                                   0, 14],
            ['Ressources Humaines',     'Récupération solde congés',              'checkbox', 'rh_conges',            null,                                                                                                   0, 15],
            ['Ressources Humaines',     'Résiliation mutuelle MGEN',             'checkbox', 'rh_mutuelle',          null,                                                                                                   0, 16],
            ['Ressources Humaines',     'Observations RH',                        'textarea', 'rh_observations',      null,                                                                                                   0, 17],
            ['Logistique',              'Restitution badge d\'accès',             'checkbox', 'log_restitution_badge',null,                                                                                                   0, 18],
            ['Logistique',              'Restitution véhicule de service',        'checkbox', 'log_restitution_vehicule',null,                                                                                               0, 19],
            ['Logistique',              'Restitution EPI',                        'checkbox', 'log_restitution_epi',  null,                                                                                                   0, 20],
            ['Logistique',              'Libération bureau / local',              'text',     'log_bureau',           null,                                                                                                   0, 21],
        ];
        $stmt_ob = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($outboarding_fields as $row) {
            $stmt_ob->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $outboarding_id]);
        }

        // Etapes par defaut pour l'outboarding
        $ob_step1_id = generate_uuid();
        $ob_step2_id = generate_uuid();
        $ob_step3_id = generate_uuid();
        $ob_step4_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$ob_step1_id, $outboarding_id, 'Responsable direct', 1]);
        $stmt_step->execute([$ob_step2_id, $outboarding_id, 'Service informatique', 2]);
        $stmt_step->execute([$ob_step3_id, $outboarding_id, 'Ressources humaines', 3]);
        $stmt_step->execute([$ob_step4_id, $outboarding_id, 'Logistique', 4]);

        // Destinataires des étapes
        // ⚠️  Ces adresses sont des VALEURS PAR DÉFAUT destinées à être remplacées
        //     par l'administrateur via admin_forms.php. Elles ne sont pas vérifiées.
        //     L'administrateur DOIT configurer les vrais destinataires avant utilisation.
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $ob_step1_id, 'responsable.direct@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $ob_step2_id, 'informatique@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $ob_step3_id, 'rh@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $ob_step4_id, 'logistique@dreets.gouv.fr']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $outboarding_id, 'responsable.direct@dreets.gouv.fr']);
        $stmt_fo->execute([generate_uuid(), $outboarding_id, 'rh@dreets.gouv.fr']);
    }
    
    // Insertion des paramètres par défaut si la table settings est vide
    $settings_count = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($settings_count == 0) {
        $defaults = [
            ['smtp_host', 'smtp.social.gouv.fr'],
            ['smtp_port', '25'],
            ['smtp_auth', '0'],
            ['smtp_secure', ''],
            ['smtp_user', ''],
            ['smtp_pass', ''],
            ['smtp_from', 'workflow@dreets.gouv.fr'],
            ['smtp_from_name', 'CircuitDémat'],
            ['delai_relance_h', '48'],
            ['token_expire_days', '30'],
            ['relance_max', '3'],
            ['app_name', 'CircuitDémat'],
            ['app_favicon', ''],
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    }
    
    // S'assurer que relance_max existe même si la table settings a déjà des entrés
    try {
        $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('relance_max', '3')")->execute();
    } catch (PDOException $e) {
        // Ignorer si déjà présent
    }
    
    // Seed formulaire onboarding s'il n'existe pas
    $onb_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'onboarding'")->fetchColumn();
    if ($onb_count == 0) {
        $onboarding_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$onboarding_id, 'onboarding', 'Accueil agent', 'Formulaire d\'accueil d\'un nouvel agent — prise de poste, création des accès et formalités d\'entrée']);

        $onboarding_fields = [
            ['Identité de l\'agent',  'Nom',                            'text',    'nom',               null,                                                           1, 1],
            ['Identité de l\'agent',  'Prénom',                         'text',    'prenom',            null,                                                           1, 2],
            ['Identité de l\'agent',  'Date de naissance',              'date',    'date_naissance',    null,                                                           1, 3],
            ['Identité de l\'agent',  'Date de prise de poste',         'date',    'date_prise_poste',  null,                                                           1, 4],
            ['Identité de l\'agent',  'Corps / Grade',                  'select',  'corps_grade',       '["Attaché d\'administration","Secrétaire administratif","Adjoint administratif","Inspecteur du travail","Contrôleur du travail","Technicien","Ingénieur","Autre"]', 1, 5],
            ['Identité de l\'agent',  'Type d\'arrivée',                'select',  'type_arrivee',      '["Mutation","Primo-recrutement","Détachement","Stage","Alternance"]', 1, 6],
            ['Identité de l\'agent',  'Service / Affectation',          'text',    'affectation',       null,                                                           1, 7],
            ['Identité de l\'agent',  'Quotité',                        'select',  'quotite',           '["100%","80%","50%"]',                                         1, 8],
            ['Informatique (IT)',     'Type de poste',                  'select',  'type_poste',        '["Fixe","Portable"]',                                          1, 9],
            ['Informatique (IT)',     'Double écran',                   'checkbox','it_double_ecran',   null,                                                           0, 10],
            ['Informatique (IT)',     'Accès RPVN',                     'checkbox','it_acces_rpvn',    null,                                                           0, 11],
            ['Informatique (IT)',     'Téléphone professionnel',        'checkbox','it_telephone_pro', null,                                                           0, 12],
            ['Informatique (IT)',     'Applicatifs métier',             'textarea','it_applicatifs',   null,                                                           0, 13],
            ['Ressources Humaines',   'Dossier administratif à constituer','checkbox','rh_dossier_admin',null,                                                          0, 14],
            ['Ressources Humaines',   'Affiliation mutuelle MGEN',      'checkbox','rh_mutuelle',      null,                                                           0, 15],
            ['Ressources Humaines',   'Visite médicale à planifier',    'checkbox','rh_visite_medicale',null,                                                          0, 16],
            ['Ressources Humaines',   'Habilitation sécurité requise',  'checkbox','rh_habilitation',  null,                                                           0, 17],
            ['Logistique',            'Bâtiment / Bureau',              'text',    'log_batiment_bureau',null,                                                          1, 18],
            ['Logistique',            'Badge d\'accès',                 'checkbox','log_badge_acces',  null,                                                           0, 19],
            ['Logistique',            'Véhicule de service',            'checkbox','log_vehicule_service',null,                                                         0, 20],
            ['Logistique',            'EPI à préparer',                 'checkbox','log_epi_requis',   null,                                                           0, 21],
        ];
        $stmt_ob = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($onboarding_fields as $row) {
            $stmt_ob->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $onboarding_id]);
        }

        // Etapes par defaut pour l'onboarding
        $onb_step1_id = generate_uuid();
        $onb_step2_id = generate_uuid();
        $onb_step3_id = generate_uuid();
        $onb_step4_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$onb_step1_id, $onboarding_id, 'Responsable direct', 1]);
        $stmt_step->execute([$onb_step2_id, $onboarding_id, 'Service informatique', 2]);
        $stmt_step->execute([$onb_step3_id, $onboarding_id, 'Ressources humaines', 3]);
        $stmt_step->execute([$onb_step4_id, $onboarding_id, 'Logistique', 4]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $onb_step1_id, 'responsable.direct@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $onb_step2_id, 'informatique@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $onb_step3_id, 'rh@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $onb_step4_id, 'logistique@dreets.gouv.fr']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $onboarding_id, 'responsable.direct@dreets.gouv.fr']);
        $stmt_fo->execute([generate_uuid(), $onboarding_id, 'rh@dreets.gouv.fr']);
    }

    // Seed formulaire "Demande de sortie hors plages" s'il n'existe pas
    $sortie_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'sortie_hors_plages'")->fetchColumn();
    if ($sortie_count == 0) {
        $sortie_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$sortie_id, 'sortie_hors_plages', 'Demande de sortie hors plages fixes', 'Demande d\'autorisation de sortie en dehors des plages horaires fixes — arrivée tardive, départ anticipé, pause prolongée']);

        $sortie_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',          null,                                                                                                   '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',             null,                                                                                                   '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',           null,                                                                                                   '', 1, 3],
            ['Agent',                  'Service / Affectation', 'text',   'service',         null,                                                                                                   '', 1, 4],
            ['Détails de la sortie',   'Type de sortie',      'select',   'type_sortie',     '["Arrivée tardive","Départ anticipé","Pause déjeuner prolongée","Absence partielle","Autre"]',   '', 1, 5],
            ['Détails de la sortie',   'Date concernée',      'date',     'date_sortie',     null,                                                                                                   '', 1, 6],
            ['Détails de la sortie',   'Heure de début',      'text',     'heure_debut',     null,                                                                                                   'Format HH:MM', 1, 7],
            ['Détails de la sortie',   'Heure de fin',        'text',     'heure_fin',       null,                                                                                                   'Format HH:MM', 1, 8],
            ['Détails de la sortie',   'Motif',               'textarea', 'motif',           null,                                                                                                   '', 1, 9],
        ];
        $stmt_so = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($sortie_fields as $row) {
            $stmt_so->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $sortie_id]);
        }

        // Etapes : Chef de service (ordre 1) → DRH (ordre 2)
        $sortie_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$sortie_step1_id, $sortie_id, 'Chef de service', 1]);
        $sortie_step2_id = generate_uuid();
        $stmt_step->execute([$sortie_step2_id, $sortie_id, 'DRH', 2]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $sortie_step1_id, 'chef.service@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $sortie_step2_id, 'drh@dreets.gouv.fr']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $sortie_id, 'chef.service@dreets.gouv.fr']);
        $stmt_fo->execute([generate_uuid(), $sortie_id, 'drh@dreets.gouv.fr']);
    }

    // Seed formulaire "Remboursement d'avance de frais" s'il n'existe pas
    $remboursement_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'remboursement_avance_frais'")->fetchColumn();
    if ($remboursement_count == 0) {
        $remboursement_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$remboursement_id, 'remboursement_avance_frais', 'Remboursement d\'avance de frais', 'Demande de remboursement d\'une avance de frais engagée dans le cadre professionnel — déplacement, fourniture, représentation']);

        $remboursement_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',                  null,                                                                                                   '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',                     null,                                                                                                   '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',                   null,                                                                                                   '', 1, 3],
            ['Agent',                  'Service / Affectation', 'text',   'service',                 null,                                                                                                   '', 1, 4],
            ['Détails de la dépense',  'Nature de la dépense', 'select',  'nature_depense',          '["Déplacement professionnel","Hébergement","Repas / Représentation","Fournitures bureautiques","Frais postaux","Autre"]', '', 1, 5],
            ['Détails de la dépense',  'Montant',             'text',     'montant',                 null,                                                                                                   'En euros TTC', 1, 6],
            ['Détails de la dépense',  'Date de la dépense',  'date',     'date_depense',            null,                                                                                                   '', 1, 7],
            ['Détails de la dépense',  'Justification',       'textarea', 'justification',           null,                                                                                                   '', 1, 8],
            ['Détails de la dépense',  'Justificatif (description)', 'text', 'justificatif_desc',  null,                                                                                            'Description du justificatif joint', 0, 9],
        ];
        $stmt_rb = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($remboursement_fields as $row) {
            $stmt_rb->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $remboursement_id]);
        }

        // Etapes : Chef de service (ordre 1) → Comptabilité (ordre 2) → Agent financier (ordre 3)
        $remboursement_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$remboursement_step1_id, $remboursement_id, 'Chef de service', 1]);
        $remboursement_step2_id = generate_uuid();
        $stmt_step->execute([$remboursement_step2_id, $remboursement_id, 'Comptabilité', 2]);
        $remboursement_step3_id = generate_uuid();
        $stmt_step->execute([$remboursement_step3_id, $remboursement_id, 'Agent financier', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $remboursement_step1_id, 'chef.service@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $remboursement_step2_id, 'comptabilite@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $remboursement_step3_id, 'agent.financier@dreets.gouv.fr']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $remboursement_id, 'comptabilite@dreets.gouv.fr']);
        $stmt_fo->execute([generate_uuid(), $remboursement_id, 'agent.financier@dreets.gouv.fr']);
    }

    // Seed formulaire "Demande de matériel suite prescription médicale" s'il n'existe pas
    $materiel_med_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'materiel_prescription'")->fetchColumn();
    if ($materiel_med_count == 0) {
        $materiel_med_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$materiel_med_id, 'materiel_prescription', 'Demande de matériel (prescription médicale)', 'Demande de matériel suite à une prescription médicale — aménagement de poste, équipement ergonomique, matériel spécifique']);

        $materiel_med_fields = [
            ['Agent',                          'Prénom',                    'text',     'prenom',                  null,                                                                                                   '', 1, 1],
            ['Agent',                          'Nom',                       'text',     'nom',                     null,                                                                                                   '', 1, 2],
            ['Agent',                          'Email',                     'text',     'email',                   null,                                                                                                   '', 1, 3],
            ['Agent',                          'Service / Affectation',      'text',     'service',                 null,                                                                                                   '', 1, 4],
            ['Agent',                          'Bureau / Lieu de travail',   'text',     'bureau',                  null,                                                                                                   '', 1, 5],
            ['Prescription médicale',          'Nature du handicap / besoin', 'select',  'nature_besoin',          '["Trou musculosquelettique","Trouble visuel","Trouble auditif","Maladie chronique","Grossesse","Autre"]', '', 1, 6],
            ['Prescription médicale',          'Date de la prescription',    'date',     'date_prescription',       null,                                                                                                   '', 1, 7],
            ['Prescription médicale',          'Médecin prescripteur',       'text',     'medecin_prescripteur',    null,                                                                                                   '', 0, 8],
            ['Matériel demandé',               'Type de matériel',           'select',   'type_materiel',           '["Fauteuil ergonomique","Repose-pieds","Écran agrandi","Clavier adapté","Souris ergonomique","Plan de travail réglable","Éclairage spécifique","Autre"]', '', 1, 9],
            ['Matériel demandé',               'Description détaillée',      'textarea', 'description_materiel',   null,                                                                                                   '', 1, 10],
            ['Matériel demandé',               'Urgence',                    'select',   'urgence',                 '["Normale","Urgente — aménagement imminent","Très urgente — arrêt de travail risque"]',                                     '', 1, 11],
            ['Matériel demandé',               'Justification médicale',     'textarea', 'justification_medicale',  null,                                                                                                   '', 1, 12],
        ];
        $stmt_mm = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($materiel_med_fields as $row) {
            $stmt_mm->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $materiel_med_id]);
        }

        // Etapes : Médecin de prévention (ordre 1) → Chef de service (ordre 2) → DSI + Logistique (parallèle, ordre 3) → DRH (ordre 4)
        $materiel_med_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$materiel_med_step1_id, $materiel_med_id, 'Médecin de prévention', 1]);
        $materiel_med_step2_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step2_id, $materiel_med_id, 'Chef de service', 2]);
        $materiel_med_step3_dsi_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step3_dsi_id, $materiel_med_id, 'DSI', 3]);
        $materiel_med_step3_log_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step3_log_id, $materiel_med_id, 'Logistique', 3]);
        $materiel_med_step4_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step4_id, $materiel_med_id, 'DRH', 4]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $materiel_med_step1_id, 'medecin.prevention@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step2_id, 'chef.service@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step3_dsi_id, 'dsi@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step3_log_id, 'logistique@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step4_id, 'drh@dreets.gouv.fr']);

        // Owners du formulaire — suivi du matériel médical
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $materiel_med_id, 'medecin.prevention@dreets.gouv.fr']);
        $stmt_fo->execute([generate_uuid(), $materiel_med_id, 'logistique@dreets.gouv.fr']);
        $stmt_fo->execute([generate_uuid(), $materiel_med_id, 'drh@dreets.gouv.fr']);
    }

    // Seed formulaire "Demande de mutation" s'il n'existe pas
    $mutation_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'mutation'")->fetchColumn();
    if ($mutation_count == 0) {
        $mutation_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$mutation_id, 'mutation', 'Demande de mutation', 'Formulaire de demande de mutation interne — mobilité entre services ou directions au sein de la DREETS']);

        $mutation_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',           null,                                                                                    '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',              null,                                                                                    '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',            null,                                                                                    '', 1, 3],
            ['Agent',                  'Corps / Grade',       'text',     'corps_grade',      null,                                                                                    '', 1, 4],
            ['Agent',                  'Service actuel',      'text',     'service_actuel',   null,                                                                                    '', 1, 5],
            ['Agent',                  'Quotité',             'select',   'quotite',          '["100%","80%","60%","50%"]',                                                            '', 1, 6],
            ['Mutation demandée',      'Service demandé',     'text',     'service_demande',  null,                                                                                    '', 1, 7],
            ['Mutation demandée',      'Direction demandée',  'text',     'direction_demandee',null,                                                                                   '', 1, 8],
            ['Mutation demandée',      'Motif',               'textarea', 'motif',            null,                                                                                    '', 1, 9],
            ['Mutation demandée',      'Date souhaitée',      'date',     'date_souhaitee',   null,                                                                                    '', 1, 10],
        ];
        $stmt_mu = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($mutation_fields as $row) {
            $stmt_mu->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $mutation_id]);
        }

        // Etapes : Chef de service actuel (ordre 1) → Chef service demandé (ordre 2) → DRH (ordre 3)
        $mutation_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$mutation_step1_id, $mutation_id, 'Chef de service actuel', 1]);
        $mutation_step2_id = generate_uuid();
        $stmt_step->execute([$mutation_step2_id, $mutation_id, 'Chef service demandé', 2]);
        $mutation_step3_id = generate_uuid();
        $stmt_step->execute([$mutation_step3_id, $mutation_id, 'DRH', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $mutation_step1_id, 'chef.service@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $mutation_step2_id, 'chef.service.demande@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $mutation_step3_id, 'drh@dreets.gouv.fr']);
    }

    // Seed formulaire "Demande de formation" s'il n'existe pas
    $formation_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'formation'")->fetchColumn();
    if ($formation_count == 0) {
        $formation_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formation_id, 'formation', 'Demande de formation', 'Formulaire de demande de formation continue — plan de formation, DIF/CPF, stage inter ou intra']);

        $formation_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',             null,                                                                                               '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',                null,                                                                                               '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',              null,                                                                                               '', 1, 3],
            ['Agent',                  'Service',             'text',     'service',            null,                                                                                               '', 1, 4],
            ['Agent',                  'Poste',               'text',     'poste',              null,                                                                                               '', 1, 5],
            ['Formation demandée',     'Intitulé formation',  'text',     'intitule_formation', null,                                                                                               '', 1, 6],
            ['Formation demandée',     'Organisme',           'text',     'organisme',          null,                                                                                               '', 1, 7],
            ['Formation demandée',     'Date début',          'date',     'date_debut',         null,                                                                                               '', 1, 8],
            ['Formation demandée',     'Date fin',            'date',     'date_fin',           null,                                                                                               '', 1, 9],
            ['Formation demandée',     'Lieu',                'text',     'lieu',               null,                                                                                               '', 1, 10],
            ['Formation demandée',     'Coût estimé',         'text',     'cout_estime',        null,                                                                                               'en euros TTC', 1, 11],
            ['Formation demandée',     'Heures DIF',          'text',     'heures_dif',         null,                                                                                               'nombre d\'heures au titre du DIF/CPF', 1, 12],
            ['Justification',          'Objectif',            'textarea', 'objectif',           null,                                                                                               '', 1, 13],
            ['Justification',          'Impact métier',       'textarea', 'impact_metier',      null,                                                                                               '', 1, 14],
            ['Justification',          'Avis du chef',        'select',   'avis_chef',          '["Favorable","Défavorable","Réservé"]',                                                             '', 1, 15],
        ];
        $stmt_fo = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($formation_fields as $row) {
            $stmt_fo->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $formation_id]);
        }

        // Etapes : Chef de service (ordre 1) → Formation (ordre 2) → DRH (ordre 3)
        $formation_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$formation_step1_id, $formation_id, 'Chef de service', 1]);
        $formation_step2_id = generate_uuid();
        $stmt_step->execute([$formation_step2_id, $formation_id, 'Formation', 2]);
        $formation_step3_id = generate_uuid();
        $stmt_step->execute([$formation_step3_id, $formation_id, 'DRH', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $formation_step1_id, 'chef.service@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $formation_step2_id, 'formation@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $formation_step3_id, 'drh@dreets.gouv.fr']);
    }

    // Seed formulaire "Demande d'accès SI" s'il n'existe pas
    $acces_si_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'acces_si'")->fetchColumn();
    if ($acces_si_count == 0) {
        $acces_si_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$acces_si_id, 'acces_si', 'Demande d\'accès SI', 'Formulaire de demande d\'accès aux systèmes d\'information — création, modification ou suppression de comptes et droits']);

        $acces_si_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',             null,                                                                                               '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',                null,                                                                                               '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',              null,                                                                                               '', 1, 3],
            ['Agent',                  'Service',             'text',     'service',            null,                                                                                               '', 1, 4],
            ['Agent',                  'Fonction',            'text',     'fonction',           null,                                                                                               '', 1, 5],
            ['Agent',                  'Date de prise de poste','date',  'date_prise_poste',   null,                                                                                               '', 1, 6],
            ['Accès demandés',         'Type d\'accès',       'select',   'type_acces',         '["Nouvel accès","Modification","Suppression"]',                                                     '', 1, 7],
            ['Accès demandés',         'Systèmes',            'textarea', 'systemes',           null,                                                                                               'Ex : APB, ENLAP, RPVN, MESSAGERIE, RÉSEAU, APPLICATIONS MÉTIER', 1, 8],
            ['Accès demandés',         'Justification',       'textarea', 'justification',      null,                                                                                               '', 1, 9],
            ['Accès demandés',         'Urgence',             'select',   'urgence',            '["Normale","Urgente - sous 48h"]',                                                                  '', 1, 10],
            ['Matériel',               'Poste de travail',    'select',   'poste_travail',      '["Poste fixe","Portable","Aucun"]',                                                                 '', 1, 11],
            ['Matériel',               'Téléphone',           'select',   'telephone',          '["Fixe","Mobile","Aucun"]',                                                                         '', 1, 12],
            ['Matériel',               'Périphériques',       'text',     'peripheriques',      null,                                                                                               'Ex : écran supplémentaire, clavier, souris, imprimante', 1, 13],
        ];
        $stmt_si = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($acces_si_fields as $row) {
            $stmt_si->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $acces_si_id]);
        }

        // Etapes : Chef de service (ordre 1) → DSI (ordre 2) → RSSI (ordre 3)
        $acces_si_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$acces_si_step1_id, $acces_si_id, 'Chef de service', 1]);
        $acces_si_step2_id = generate_uuid();
        $stmt_step->execute([$acces_si_step2_id, $acces_si_id, 'DSI', 2]);
        $acces_si_step3_id = generate_uuid();
        $stmt_step->execute([$acces_si_step3_id, $acces_si_id, 'RSSI', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $acces_si_step1_id, 'chef.service@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $acces_si_step2_id, 'dsi@dreets.gouv.fr']);
        $stmt_sr->execute([generate_uuid(), $acces_si_step3_id, 'rssi@dreets.gouv.fr']);
    }

    } catch (PDOException $e) {
        // Datatype mismatch ou autre erreur — la base est probablement
        // encore dans l'ancien format (id INTEGER avant migration v9).
        // On retentera le seeding après les migrations versionnées.
        $seed_needed = true;
    }

    // ── Legacy migrations (unversioned — always run for backward compat) ──
    try { $pdo->exec("ALTER TABLE submissions ADD COLUMN closed_at DATETIME"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE tokens ADD COLUMN relance_at DATETIME"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE submissions ADD COLUMN status TEXT DEFAULT 'en_cours'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE tokens ADD COLUMN expires_at DATETIME"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE tokens ADD COLUMN relance_count INTEGER DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE forms ADD COLUMN deadline_field TEXT DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE form_fields ADD COLUMN hint TEXT DEFAULT ''"); } catch (PDOException $e) {}
    
    // ── Versioned migrations ───────────────────────────────────
    // Version 1: add file_data BLOB column to attachments
    if ($current_version < 1) {
        try {
            $pdo->exec("ALTER TABLE attachments ADD COLUMN file_data BLOB");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([1]);
            $current_version = 1;
        } catch (PDOException $e) {
            // Column already exists — mark as migrated
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([1]); } catch (PDOException $e2) {}
        }
    }
    
    // Version 2: add rgpd_consent column to submissions
    if ($current_version < 2) {
        try {
            $pdo->exec("ALTER TABLE submissions ADD COLUMN rgpd_consent INTEGER DEFAULT 0");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([2]);
            $current_version = 2;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([2]); } catch (PDOException $e2) {}
        }
    }
    
    // Version 3: add legal_mentions and retention_months settings
    if ($current_version < 3) {
        try {
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('legal_mentions', 'Les données collectées sont traitées dans le cadre de la dématérialisation des procédures internes de la DREETS. Conformément au RGPD, vous disposez d\\'un droit d\\'accès, de rectification et d\\'effacement de vos données. Contact : CIL DREETS. Durée de conservation : 24 mois après clôture.', datetime('now'))");
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('retention_months', '24', datetime('now'))");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([3]);
            $current_version = 3;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([3]); } catch (PDOException $e2) {}
        }
    }
    
    // Version 4: add webhook_url and webhook_events settings
    if ($current_version < 4) {
        try {
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('webhook_url', '', datetime('now'))");
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('webhook_events', 'workflow_complete,submission_cancelled', datetime('now'))");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([4]);
            $current_version = 4;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([4]); } catch (PDOException $e2) {}
        }
    }
    
    // Version 5: add rate limiting table
    if ($current_version < 5) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                id TEXT PRIMARY KEY NOT NULL,
                action_key TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([5]);
            $current_version = 5;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([5]); } catch (PDOException $e2) {}
        }
    }

    // Version 6: add form_owners table
    if ($current_version < 6) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS form_owners (
                id TEXT PRIMARY KEY NOT NULL,
                form_id TEXT NOT NULL,
                email TEXT NOT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(form_id, email),
                FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
            )");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([6]);
            $current_version = 6;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([6]); } catch (PDOException $e2) {}
        }
    }

    // Version 7: add uuid column to forms + generate for existing rows
    if ($current_version < 7) {
        try {
            try { $pdo->exec("ALTER TABLE forms ADD COLUMN uuid TEXT"); } catch (PDOException $e) {}
            // Generate UUIDs for existing forms that don't have one
            $forms_without_uuid = $pdo->query("SELECT id FROM forms WHERE uuid IS NULL OR uuid = ''")->fetchAll(PDO::FETCH_COLUMN);
            $stmt_upd = $pdo->prepare("UPDATE forms SET uuid = ? WHERE id = ?");
            foreach ($forms_without_uuid as $fid) {
                $stmt_upd->execute([generate_uuid(), $fid]);
            }
            // Make uuid unique and not null
            try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_forms_uuid ON forms(uuid)"); } catch (PDOException $e) {}
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([7]);
            $current_version = 7;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([7]); } catch (PDOException $e2) {}
        }
    }

    // Version 8: add lazy_cron tracking table
    if ($current_version < 8) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS lazy_cron (
                task_key TEXT PRIMARY KEY,
                last_run DATETIME NOT NULL,
                run_count INTEGER DEFAULT 0
            )");
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([8]);
            $current_version = 8;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([8]); } catch (PDOException $e2) {}
        }
    }

    // Version 9: migrate all INTEGER PKs and FKs to TEXT (UUID)
    if ($current_version < 9) {
        try {
            $pdo->exec("PRAGMA foreign_keys = OFF");

            // ── ÉTAPE 1 : Construire la mapping old_int_id → uuid pour forms ──
            // AVANT de dropper la table forms, on lit la correspondance id (int) → uuid
            $form_id_map = []; // old_int_id => uuid
            $old_forms = $pdo->query("SELECT id, uuid FROM forms")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($old_forms as $frow) {
                $form_id_map[$frow['id']] = $frow['uuid'];
            }

            // ── ÉTAPE 2 : Migrer forms ──
            $pdo->exec("CREATE TABLE forms_new (
                id TEXT PRIMARY KEY NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                label TEXT NOT NULL,
                description TEXT,
                actif INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                deadline_field TEXT DEFAULT ''
            )");
            $pdo->exec("INSERT INTO forms_new (id, slug, label, description, actif, created_at, deadline_field)
                SELECT uuid, slug, label, description, actif, created_at, deadline_field FROM forms");
            $pdo->exec("DROP TABLE forms");
            $pdo->exec("ALTER TABLE forms_new RENAME TO forms");

            // ── ÉTAPE 3 : Migrer steps ──
            $old_steps = $pdo->query("SELECT * FROM steps")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE steps");
            $pdo->exec("CREATE TABLE steps (
                id TEXT PRIMARY KEY NOT NULL,
                form_id TEXT NOT NULL,
                label TEXT NOT NULL,
                ordre INTEGER NOT NULL,
                actif INTEGER DEFAULT 1,
                FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
            )");
            $step_id_map = []; // old_int_id => new_uuid_id
            $stmt_ins = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, ?)");
            foreach ($old_steps as $row) {
                $new_id = generate_uuid();
                $new_form_id = $form_id_map[$row['form_id']] ?? $row['form_id'];
                $stmt_ins->execute([$new_id, $new_form_id, $row['label'], $row['ordre'], $row['actif']]);
                $step_id_map[$row['id']] = $new_id;
            }

            // ── ÉTAPE 4 : Migrer step_recipients ──
            $old_sr = $pdo->query("SELECT * FROM step_recipients")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE step_recipients");
            $pdo->exec("CREATE TABLE step_recipients (
                id TEXT PRIMARY KEY NOT NULL,
                step_id TEXT NOT NULL,
                email TEXT NOT NULL,
                FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
            foreach ($old_sr as $row) {
                $new_step_id = $step_id_map[$row['step_id']] ?? $row['step_id'];
                $stmt_ins->execute([generate_uuid(), $new_step_id, $row['email']]);
            }

            // ── ÉTAPE 5 : Migrer submissions ──
            $old_subs = $pdo->query("SELECT * FROM submissions")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE submissions");
            $pdo->exec("CREATE TABLE submissions (
                id TEXT PRIMARY KEY NOT NULL,
                form_id TEXT NOT NULL,
                data TEXT NOT NULL,
                submitted_by TEXT NOT NULL,
                submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME,
                status TEXT DEFAULT 'en_cours',
                rgpd_consent INTEGER DEFAULT 0,
                FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
            )");
            $sub_id_map = [];
            $stmt_ins = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status, rgpd_consent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_subs as $row) {
                $new_id = generate_uuid();
                $new_form_id = $form_id_map[$row['form_id']] ?? $row['form_id'];
                $stmt_ins->execute([$new_id, $new_form_id, $row['data'], $row['submitted_by'], $row['submitted_at'], $row['closed_at'], $row['status'], $row['rgpd_consent'] ?? 0]);
                $sub_id_map[$row['id']] = $new_id;
            }

            // ── ÉTAPE 6 : Migrer tokens ──
            $old_tokens = $pdo->query("SELECT * FROM tokens")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE tokens");
            $pdo->exec("CREATE TABLE tokens (
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
            )");
            $token_id_map = [];
            $stmt_ins = $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, expires_at, relance_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_tokens as $row) {
                $new_id = generate_uuid();
                $new_sub_id = $sub_id_map[$row['submission_id']] ?? $row['submission_id'];
                $new_step_id = $step_id_map[$row['step_id']] ?? $row['step_id'];
                $stmt_ins->execute([$new_id, $new_sub_id, $new_step_id, $row['email'], $row['token'], $row['sent_at'], $row['done_at'], $row['relance_at'], $row['expires_at'], $row['relance_count'] ?? 0]);
                $token_id_map[$row['id']] = $new_id;
            }

            // ── ÉTAPE 7 : Migrer form_fields ──
            $old_ff = $pdo->query("SELECT * FROM form_fields")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE form_fields");
            $pdo->exec("CREATE TABLE form_fields (
                id TEXT PRIMARY KEY NOT NULL,
                form_id TEXT NOT NULL,
                label TEXT NOT NULL,
                field_type TEXT NOT NULL DEFAULT 'text',
                field_name TEXT NOT NULL,
                options TEXT,
                required INTEGER DEFAULT 0,
                ordre INTEGER DEFAULT 0,
                card_group TEXT DEFAULT 'Général',
                hint TEXT DEFAULT '',
                FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, hint) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_ff as $row) {
                $new_form_id = $form_id_map[$row['form_id']] ?? $row['form_id'];
                $stmt_ins->execute([generate_uuid(), $new_form_id, $row['label'], $row['field_type'], $row['field_name'], $row['options'], $row['required'], $row['ordre'], $row['card_group'], $row['hint'] ?? '']);
            }

            // ── ÉTAPE 8 : Migrer admins ──
            $old_admins = $pdo->query("SELECT * FROM admins")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE admins");
            $pdo->exec("CREATE TABLE admins (
                id TEXT PRIMARY KEY NOT NULL,
                email TEXT UNIQUE NOT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, ?)");
            foreach ($old_admins as $row) {
                $stmt_ins->execute([generate_uuid(), $row['email'], $row['added_at']]);
            }

            // ── ÉTAPE 9 : Migrer admin_requests ──
            $old_ar = $pdo->query("SELECT * FROM admin_requests")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE admin_requests");
            $pdo->exec("CREATE TABLE admin_requests (
                id TEXT PRIMARY KEY NOT NULL,
                email TEXT UNIQUE NOT NULL,
                requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT NOT NULL DEFAULT 'pending',
                token TEXT UNIQUE NOT NULL
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, ?, ?, ?)");
            foreach ($old_ar as $row) {
                $stmt_ins->execute([generate_uuid(), $row['email'], $row['requested_at'], $row['status'], $row['token']]);
            }

            // ── ÉTAPE 10 : Migrer audit_log ──
            $old_al = $pdo->query("SELECT * FROM audit_log")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE audit_log");
            $pdo->exec("CREATE TABLE audit_log (
                id TEXT PRIMARY KEY NOT NULL,
                action TEXT NOT NULL,
                target TEXT,
                detail TEXT,
                actor TEXT NOT NULL,
                ip TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO audit_log (id, action, target, detail, actor, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_al as $row) {
                $stmt_ins->execute([generate_uuid(), $row['action'], $row['target'], $row['detail'], $row['actor'], $row['ip'], $row['created_at']]);
            }

            // ── ÉTAPE 11 : Migrer alert_rules ──
            $old_arules = $pdo->query("SELECT * FROM alert_rules")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE alert_rules");
            $pdo->exec("CREATE TABLE alert_rules (
                id TEXT PRIMARY KEY NOT NULL,
                form_id TEXT NOT NULL,
                days_before INTEGER NOT NULL DEFAULT 5,
                condition_type TEXT NOT NULL DEFAULT 'steps_incomplete',
                notify_who TEXT NOT NULL DEFAULT 'admin',
                label TEXT NOT NULL,
                actif INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
            )");
            $arule_id_map = [];
            $stmt_ins = $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_arules as $row) {
                $new_id = generate_uuid();
                $new_form_id = $form_id_map[$row['form_id']] ?? $row['form_id'];
                $stmt_ins->execute([$new_id, $new_form_id, $row['days_before'], $row['condition_type'], $row['notify_who'], $row['label'], $row['actif'], $row['created_at']]);
                $arule_id_map[$row['id']] = $new_id;
            }

            // ── ÉTAPE 12 : Migrer alert_log ──
            $old_alog = $pdo->query("SELECT * FROM alert_log")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE alert_log");
            $pdo->exec("CREATE TABLE alert_log (
                id TEXT PRIMARY KEY NOT NULL,
                rule_id TEXT NOT NULL,
                submission_id TEXT NOT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                message TEXT,
                FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
                FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO alert_log (id, rule_id, submission_id, sent_at, message) VALUES (?, ?, ?, ?, ?)");
            foreach ($old_alog as $row) {
                $new_rule_id = $arule_id_map[$row['rule_id']] ?? $row['rule_id'];
                $new_sub_id = $sub_id_map[$row['submission_id']] ?? $row['submission_id'];
                $stmt_ins->execute([generate_uuid(), $new_rule_id, $new_sub_id, $row['sent_at'], $row['message']]);
            }

            // ── ÉTAPE 13 : Migrer attachments ──
            $old_att = $pdo->query("SELECT * FROM attachments")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE attachments");
            $pdo->exec("CREATE TABLE attachments (
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
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_att as $row) {
                $new_sub_id = $sub_id_map[$row['submission_id']] ?? $row['submission_id'];
                $stmt_ins->execute([generate_uuid(), $new_sub_id, $row['field_name'], $row['original_name'], $row['stored_name'], $row['mime_type'], $row['file_size'], $row['file_data'], $row['uploaded_at']]);
            }

            // ── ÉTAPE 14 : Migrer delegations ──
            $old_deleg = $pdo->query("SELECT * FROM delegations")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE delegations");
            $pdo->exec("CREATE TABLE delegations (
                id TEXT PRIMARY KEY NOT NULL,
                token_id TEXT NOT NULL,
                from_email TEXT NOT NULL,
                to_email TEXT NOT NULL,
                reason TEXT,
                delegated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                new_token_id TEXT,
                FOREIGN KEY (token_id) REFERENCES tokens(id) ON DELETE CASCADE
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($old_deleg as $row) {
                $new_token_id = $token_id_map[$row['token_id']] ?? $row['token_id'];
                $new_new_token_id = isset($row['new_token_id']) ? ($token_id_map[$row['new_token_id']] ?? $row['new_token_id']) : null;
                $stmt_ins->execute([generate_uuid(), $new_token_id, $row['from_email'], $row['to_email'], $row['reason'], $row['delegated_at'], $new_new_token_id]);
            }

            // ── ÉTAPE 15 : Migrer form_owners ──
            $old_fo = $pdo->query("SELECT * FROM form_owners")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE form_owners");
            $pdo->exec("CREATE TABLE form_owners (
                id TEXT PRIMARY KEY NOT NULL,
                form_id TEXT NOT NULL,
                email TEXT NOT NULL,
                added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(form_id, email),
                FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO form_owners (id, form_id, email, added_at) VALUES (?, ?, ?, ?)");
            foreach ($old_fo as $row) {
                $new_form_id = $form_id_map[$row['form_id']] ?? $row['form_id'];
                $stmt_ins->execute([generate_uuid(), $new_form_id, $row['email'], $row['added_at']]);
            }

            // ── ÉTAPE 16 : Migrer rate_limits ──
            $old_rl = $pdo->query("SELECT * FROM rate_limits")->fetchAll(PDO::FETCH_ASSOC);
            $pdo->exec("DROP TABLE rate_limits");
            $pdo->exec("CREATE TABLE rate_limits (
                id TEXT PRIMARY KEY NOT NULL,
                action_key TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $stmt_ins = $pdo->prepare("INSERT INTO rate_limits (id, action_key, ip, attempted_at) VALUES (?, ?, ?, ?)");
            foreach ($old_rl as $row) {
                $stmt_ins->execute([generate_uuid(), $row['action_key'], $row['ip'], $row['attempted_at']]);
            }

            // Ré-activer les FK
            $pdo->exec("PRAGMA foreign_keys = ON");

            // Vérification d'intégrité
            $pdo->exec("PRAGMA integrity_check");

            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([9]);
            $current_version = 9;
        } catch (PDOException $e) {
            // Ré-activer les FK même en cas d'échec
            try { $pdo->exec("PRAGMA foreign_keys = ON"); } catch (PDOException $e2) {}
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([9]); } catch (PDOException $e2) {}
        }
    }

    // ── Migration v10 : Paramètres de vérification email ──────────
    if ($current_version < 10) {
        try {
            $v10_settings = [
                ['mail_dry_run',      '1'],  // Sécurité : dry-run activé par défaut
                ['email_verify_mode', 'none'], // none | ldap | smtp
                ['ldap_host',         ''],     // ex: ldap.dreets.gouv.fr
                ['ldap_port',         '389'],
                ['ldap_base_dn',      ''],     // ex: DC=dreets,DC=gouv,DC=fr
                ['ldap_bind_dn',      ''],     // Compte de service lecture seule
                ['ldap_bind_pass',    ''],
                ['ldap_filter',       '(mail={email})'],
            ];
            $stmt_v10 = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");
            foreach ($v10_settings as $row) {
                $stmt_v10->execute($row);
            }

            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([10]);
            $current_version = 10;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([10]); } catch (PDOException $e2) {}
        }
    }

    // ── Vérification/correction automatique des INTEGER PK ──
    // Certaines bases ont pu échapper à la migration v9 (échec silencieux,
    // restore d'un dump ancien, etc.). On vérifie à CHAQUE accès si des
    // tables ont encore un PK INTEGER et on les corrige automatiquement.
    // Cette vérification est rapide (PRAGMA table_info) et ne fait rien
    // si le schéma est déjà correct.
    ensure_text_ids($pdo);

    // Seed des regles d'alerte par defaut si la table est vide
    try {
        $alert_count = $pdo->query("SELECT COUNT(*) FROM alert_rules")->fetchColumn();
        if ($alert_count == 0) {
            // Onboarding : alerter 5 jours et 2 jours avant la prise de poste
            $onb = $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding' LIMIT 1")->fetchColumn();
            if ($onb) {
                $stmt_ar = $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt_ar->execute([generate_uuid(), $onb, 5, 'steps_incomplete', 'admin', 'Alerte J-5 : étapes non complétées']);
                $stmt_ar->execute([generate_uuid(), $onb, 2, 'steps_incomplete', 'admin', 'Alerte J-2 : étapes non complétées']);
                // Mettre à jour le deadline_field pour l'onboarding
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")->execute(['date_prise_poste', $onb]);
            }
            // Outboarding : alerter 5 jours et 2 jours avant le départ
            $ob = $pdo->query("SELECT id FROM forms WHERE slug = 'outboarding' LIMIT 1")->fetchColumn();
            if ($ob) {
                $stmt_ar = $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt_ar->execute([generate_uuid(), $ob, 5, 'steps_incomplete', 'admin', 'Alerte J-5 : étapes non complétées']);
                $stmt_ar->execute([generate_uuid(), $ob, 2, 'steps_incomplete', 'admin', 'Alerte J-2 : étapes non complétées']);
                // Mettre à jour le deadline_field pour l'outboarding
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")->execute(['date_depart', $ob]);
            }
        }
    } catch (PDOException $e) {
        // Ignorer si déjà fait
    }
    
    // Seed webhook settings if empty
    try {
        $webhook_check = $pdo->query("SELECT COUNT(*) FROM settings WHERE key IN ('webhook_url', 'webhook_events')")->fetchColumn();
        if ($webhook_check < 2) {
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('webhook_url', '', datetime('now'))");
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('webhook_events', 'workflow_complete,submission_cancelled', datetime('now'))");
        }
    } catch (PDOException $e) {}
    
    // Version 11: admin_email en base (remplace le define ADMIN_EMAIL de config.php)
    if ($current_version < 11) {
        try {
            // Insérer l'admin_email actuel s'il n'existe pas déjà en base
            $admin_email_value = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '';
            $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('admin_email', ?, datetime('now'))")->execute([$admin_email_value]);
            $pdo->prepare("INSERT INTO schema_version (version) VALUES (?)")->execute([11]);
            $current_version = 11;
        } catch (PDOException $e) {
            try { $pdo->prepare("INSERT OR IGNORE INTO schema_version (version) VALUES (?)")->execute([11]); } catch (PDOException $e2) {}
        }
    }
    
    // Migration des données existantes : peupler la colonne status à partir de closed_at
    try {
        // Les soumissions avec closed_at commençant par REFUSED: sont refusees
        $pdo->exec("UPDATE submissions SET status = 'refuse' WHERE closed_at LIKE 'REFUSED:%' AND (status IS NULL OR status = 'en_cours')");
        // Les soumissions avec closed_at non null (et pas REFUSED) sont validees
        $pdo->exec("UPDATE submissions SET status = 'valide' WHERE closed_at IS NOT NULL AND closed_at NOT LIKE 'REFUSED:%' AND (status IS NULL OR status = 'en_cours')");
        // Nettoyer closed_at : enlever le prefixe REFUSED: et mettre la vraie date
        $pdo->exec("UPDATE submissions SET closed_at = SUBSTR(closed_at, 9) WHERE closed_at LIKE 'REFUSED:%'");
    } catch (PDOException $e) {
        // Ignorer si la migration a déjà été faite
    }

    // ═══════════════════════════════════════════════════════════════
    // SEEDING DIFFÉRÉ — si le seeding initial a échoué (datatype mismatch
    // sur une base pré-v9 avec id INTEGER), on le retente ici après les
    // migrations versionnées qui ont converti les colonnes en TEXT.
    // ═══════════════════════════════════════════════════════════════
    if (!empty($seed_needed)) {
        try {
            // Admin par défaut
            $count_stmt = $pdo->query("SELECT COUNT(*) FROM admins");
            if ($count_stmt->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, ?)")
                    ->execute([generate_uuid(), get_admin_email(), date('Y-m-d H:i:s')]);
            }

            // Formulaires par défaut (uniquement ceux qui n'existent pas encore)
            $default_forms = [
                ['slug' => 'outboarding', 'label' => 'Départ agent', 'desc' => 'Formulaire de départ d\'un agent — restitution du matériel, cloture des accès et formalités de fin de contrat'],
                ['slug' => 'onboarding',  'label' => 'Accueil agent',  'desc' => 'Formulaire d\'accueil d\'un nouvel agent — prise de poste, création des accès et formalités d\'entrée'],
                ['slug' => 'sortie-hors-plages', 'label' => 'Sortie hors plages', 'desc' => 'Autorisation de sortie hors plages horaires'],
                ['slug' => 'remboursement-avance-frais', 'label' => 'Remboursement / Avance de frais', 'desc' => 'Demande de remboursement ou avance de frais'],
                ['slug' => 'materiel-prescription', 'label' => 'Matériel — Prescription', 'desc' => 'Prescription de matériel informatique ou bureautique'],
                ['slug' => 'mutation', 'label' => 'Mutation', 'desc' => 'Demande de mutation'],
                ['slug' => 'formation', 'label' => 'Formation', 'desc' => 'Demande de formation'],
                ['slug' => 'acces-si', 'label' => 'Accès SI', 'desc' => 'Demande de création, modification ou suppression d\'un accès au système d\'information'],
            ];
            foreach ($default_forms as $df) {
                $exists = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE slug = ?");
                $exists->execute([$df['slug']]);
                if ($exists->fetchColumn() == 0) {
                    $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
                        ->execute([generate_uuid(), $df['slug'], $df['label'], $df['desc']]);
                }
            }

            // Paramètres par défaut
            $settings_count = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
            if ($settings_count == 0) {
                $defaults = [
                    ['smtp_host', 'smtp.social.gouv.fr'],
                    ['smtp_port', '25'],
                    ['smtp_auth', '0'],
                    ['smtp_secure', ''],
                    ['smtp_user', ''],
                    ['smtp_pass', ''],
                    ['smtp_from', 'workflow@dreets.gouv.fr'],
                    ['smtp_from_name', 'CircuitDémat'],
                    ['delai_relance_h', '48'],
                    ['token_expire_days', '30'],
                    ['relance_max', '3'],
                    ['app_name', 'CircuitDémat'],
                    ['app_favicon', ''],
                ];
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
                foreach ($defaults as $row) {
                    $stmt->execute($row);
                }
            }
        } catch (PDOException $e) {
            // Silencieux — le seeding sera retenté au prochain chargement
        }
    }
}

function generate_token(): string {
    return bin2hex(random_bytes(32));
}

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

/**
 * Genere automatiquement un field_name a partir d'un libelle
 * Ex: "Date de prise de poste" → "date_de_prise_de_poste"
 * Ex: "Type d'arrivée" → "type_arrivee"
 */
function generate_field_name(string $label): string {
    // Minuscules (fallback si mbstring absent)
    $name = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    // Supprimer les accents
    if (function_exists('transliterator_transliterate')) {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        if ($transliterated !== false) {
            $name = $transliterated;
        }
    }
    // Fallback manuel si intl pas dispo ou a échoué
    $name = str_replace(
        ['à','â','ä','é','è','ê','ë','ï','î','ô','ö','ù','û','ü','ç','œ','æ','ÿ'],
        ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','c','oe','ae','y'],
        $name
    );
    // Remplacer tout ce qui n'est pas alphanumérique par un underscore
    $name = preg_replace('/[^a-z0-9]+/', '_', $name);
    // Nettoyer les underscores en double et en bordure
    $name = trim($name, '_');
    $name = preg_replace('/_+/', '_', $name);
    return $name ?: 'champ';
}

/**
 * Génère automatiquement un slug unique à partir d'un libellé.
 * Ex: "Accueil agent" → "accueil_agent"
 * Ex: "Demande de congé" → "demande_de_conge"
 * Si le slug existe déjà, ajoute un suffixe numérique : "onboarding_agent_2"
 *
 * Le slug n'est JAMAIS visible par l'utilisateur final — c'est un identifiant
 * technique interne utilisé uniquement dans les URLs (form.php?f=onboarding).
 */
function generate_slug(string $label, ?string $exclude_form_id = null): string {
    $base = generate_field_name($label);
    if (empty($base)) $base = 'formulaire';

    $pdo = get_pdo();
    $slug = $base;
    $suffix = 2;

    while (true) {
        $sql = "SELECT COUNT(*) FROM forms WHERE slug = ?";
        $params = [$slug];
        if ($exclude_form_id !== null) {
            $sql .= " AND id != ?";
            $params[] = $exclude_form_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '_' . $suffix;
        $suffix++;
    }
}

/**
 * Convertit une liste d'options (une par ligne) en JSON array
 * Ex: "Option A\nOption B" → '["Option A","Option B"]'
 * Si c'est déjà du JSON valide, le retourne tel quel
 */
function parse_options_input(string $input): ?string {
    $input = trim($input);
    if (empty($input)) return null;
    
    // Vérifier si c'est déjà du JSON valide
    $decoded = json_decode($input, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $input;
    }
    
    // Traiter comme une liste une option par ligne
    $lines = array_filter(array_map('trim', explode("\n", $input)));
    if (empty($lines)) return null;
    
    return json_encode($lines, JSON_UNESCAPED_UNICODE);
}

// ── NAVIGATION PARTAGÉE ────────────────────────────────────────

/**
 * Génère le bandeau de navigation commun à toutes les pages.
 *
 * Alias de render_header() pour compatibilité ascendante.
 * Les pages existantes qui appellent render_nav() continuent de fonctionner.
 *
 * @param string $current_page  Identifiant de la page courante pour marquage actif
 * @param array  $extra_admin_links Liens admin supplémentaires
 * @return string HTML du bandeau <nav>
 */
function render_nav(string $current_page = '', array $extra_admin_links = []): string {
    return render_header($current_page, $extra_admin_links);
}

/**
 * Génère l'en-tête complet : sidebar + topbar + ouverture du contenu.
 *
 * Structure HTML :
 *   <div class="app-layout">
 *     <nav class="sidebar">
 *       [Logo losange ◆ + DREETS]
 *       [Navigation]
 *       [Carte utilisateur]
 *     </nav>
 *     <div class="main-area">
 *       <div class="topbar">[Fil d'Ariane + Actions]</div>
 *       <div class="content">
 *
 * Chaque page doit fermer par </div></div></div> avant render_footer().
 *
 * @param string $current_page  Identifiant de la page courante pour marquage actif
 * @param array  $extra_admin_links Liens admin supplémentaires (clé => ['href'=>…, 'label'=>…, 'icon'=>…])
 * @return string HTML de l'en-tête complet
 */
function render_header(string $current_page = '', array $extra_admin_links = []): string {
    $user = get_auth_user();
    $is_admin = is_admin_user();

    // Compteur de validations en attente pour le badge
    $pending_count = 0;
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.email = ? AND t.done_at IS NULL AND t.cancelled_at IS NULL
              AND (t.expires_at IS NULL OR t.expires_at > datetime('now'))
              AND s.closed_at IS NULL
        ");
        $stmt->execute([$user]);
        $pending_count = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        // Ignorer silencieusement
    }

    // Liens principaux — toujours visibles
    $main_links = [
        'accueil'         => ['href' => 'index.php',        'label' => 'Accueil',         'icon' => '🏠'],
        'mes_demandes'    => ['href' => 'my_submissions.php','label' => 'Mes demandes',   'icon' => '📋'],
        'mes_validations' => ['href' => 'my_validations.php','label' => 'Mes validations', 'icon' => '✅'],
        'docs'            => ['href' => 'docs.php',          'label' => 'Documentation',   'icon' => '📖'],
    ];

    // Liens admin — toujours présents pour les admins
    $admin_links = [
        'forms'      => ['href' => 'admin_forms.php',      'label' => 'Formulaires',   'icon' => '📝'],
        'dashboard'  => ['href' => 'dashboard.php',        'label' => 'Supervision',   'icon' => '📊'],
        'settings'   => ['href' => 'admin_settings.php',   'label' => 'Paramètres',    'icon' => '⚙'],
    ];

    // ── Build sidebar nav items ──────────────────────────────
    $nav_html = '';
    $nav_html .= '<div class="sidebar-section-title">Navigation</div>';
    foreach ($main_links as $key => $link) {
        $active_cls = ($current_page === $key) ? ' active' : '';
        $badge = '';
        if ($key === 'mes_validations' && $pending_count > 0) {
            $badge = '<span class="sidebar-badge" aria-label="' . $pending_count . ' en attente">' . $pending_count . '</span>';
        }
        $nav_html .= '<a href="' . $link['href'] . '" class="sidebar-item' . $active_cls . '">'
            . '<span class="sidebar-item-icon" aria-hidden="true">' . $link['icon'] . '</span>'
            . '<span class="sidebar-item-label">' . $link['label'] . '</span>'
            . $badge
            . '</a>';
    }

    if ($is_admin) {
        $nav_html .= '<div class="sidebar-section-title">Administration</div>';
        foreach ($admin_links as $key => $link) {
            $active_cls = ($current_page === $key) ? ' active' : '';
            $nav_html .= '<a href="' . $link['href'] . '" class="sidebar-item' . $active_cls . '">'
                . '<span class="sidebar-item-icon" aria-hidden="true">' . $link['icon'] . '</span>'
                . '<span class="sidebar-item-label">' . $link['label'] . '</span>'
                . '</a>';
        }
        foreach ($extra_admin_links as $key => $link) {
            $active_cls = ($current_page === $key) ? ' active' : '';
            $nav_html .= '<a href="' . $link['href'] . '" class="sidebar-item' . $active_cls . '">'
                . '<span class="sidebar-item-icon" aria-hidden="true">' . $link['icon'] . '</span>'
                . '<span class="sidebar-item-label">' . $link['label'] . '</span>'
                . '</a>';
        }
    }

    // ── User initials for avatar ─────────────────────────────
    $user_initials = strtoupper(substr($user, 0, 1));

    // ── Topbar right section ─────────────────────────────────
    $notif_dot = $pending_count > 0 ? '<span class="topbar-notif-dot"></span>' : '';

    // ── Output full layout ───────────────────────────────────
    return '<div class="app-layout">'
        . '<nav class="sidebar" aria-label="Navigation principale">'
        .   '<a href="index.php" class="sidebar-brand">'
        .     '<span class="sidebar-logo-mark" aria-hidden="true">&#9670;</span>'
        .     '<span class="sidebar-brand-text">' . h(get_app_name()) . '</span>'
        .   '</a>'
        .   '<div class="sidebar-nav">'
        .     $nav_html
        .   '</div>'
        .   '<div class="sidebar-user">'
        .     '<div class="sidebar-user-card">'
        .       '<span class="sidebar-user-avatar">' . $user_initials . '</span>'
        .       '<span class="sidebar-user-email" title="' . h($user) . '">' . h($user) . '</span>'
        .     '</div>'
        .   '</div>'
        . '</nav>'
        . '<div class="main-area">'
        .   '<div class="topbar">'
        .     '<div class="topbar-left">'
        .       '<div class="topbar-breadcrumb">'
        .         '<a href="index.php">Accueil</a>'
        .       '</div>'
        .     '</div>'
        .     '<div class="topbar-right">'
        .       '<a href="my_validations.php" class="topbar-icon-btn" title="Mes validations">' . $notif_dot . '✅</a>'
        .       '<a href="form.php?f=onboarding" class="topbar-cta">+ Nouvelle demande</a>'
        .     '</div>'
        .   '</div>'
        .   '<div class="content">';
}

/**
 * Génère un fil d'Ariane.
 *
 * @param array $breadcrumbs Tableau de [label, href] du plus haut au plus bas
 *                           Le dernier élément est la page courante (sans lien)
 * @return string HTML du fil d'Ariane
 */
function render_breadcrumb(array $breadcrumbs): string {
    if (empty($breadcrumbs)) return '';

    $items = [];
    $total = count($breadcrumbs);
    foreach ($breadcrumbs as $i => $crumb) {
        $label = h($crumb[0]);
        if ($i === $total - 1) {
            // Dernier = page courante
            $items[] = '<span aria-current="page" class="current">' . $label . '</span>';
        } else {
            $items[] = '<a href="' . h($crumb[1]) . '">' . $label . '</a>';
        }
    }

    return '<nav aria-label="Fil d\'Ariane" class="breadcrumb">
  ' . implode(' <span aria-hidden="true" class="separator">›</span> ', $items) . '
</nav>';
}

// ── FOOTER ────────────────────────────────────────────────────
function render_footer(): string {
    return '</div><!-- /.content -->'
         . '</div><!-- /.main-area -->'
         . '</div><!-- /.app-layout -->'
         . '<footer>'
         . '<a href="changelog.php" title="Voir le journal des modifications">v' . h(APP_VERSION) . '</a>'
         . ' · ' . h(get_app_name())
         . '</footer>';
}

// ── ERROR PAGES ────────────────────────────────────────────────
/**
 * Affiche une page d'erreur HTML complète et arrête l'exécution.
 *
 * @param int    $code      Code HTTP (403, 404, 400, 401, 500…)
 * @param string $title     Titre court (ex: "Accès refusé")
 * @param string $message   Message descriptif
 * @param string $hint      Conseil / marche à suivre (optionnel)
 * @param string $back_url  URL du bouton de retour (défaut: index.php)
 */
function render_error_page(int $code, string $title, string $message, string $hint = '', string $back_url = 'index.php'): void {
    http_response_code($code);

    // Icônes SVG selon le code d'erreur
    $icons = [
        403 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#c0392b" stroke-width="5" fill="#fde8e8"/><rect x="38" y="28" width="24" height="28" rx="4" fill="#c0392b"/><circle cx="50" cy="30" r="2.5" fill="#fde8e8"/><path d="M50 42v8" stroke="#fde8e8" stroke-width="3" stroke-linecap="round"/><circle cx="50" cy="56" r="2" fill="#fde8e8"/><path d="M30 72 Q50 65 70 72" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/></svg>',
        404 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#003189" stroke-width="5" fill="#e8eaf6"/><path d="M30 70 L50 30 L70 70" stroke="#003189" stroke-width="4" fill="none" stroke-linecap="round" stroke-linejoin="round"/><line x1="38" y1="58" x2="62" y2="58" stroke="#003189" stroke-width="4" stroke-linecap="round"/><circle cx="50" cy="26" r="3" fill="#003189"/></svg>',
        400 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#b45309" stroke-width="5" fill="#fff3e0"/><path d="M50 30v24" stroke="#b45309" stroke-width="5" stroke-linecap="round"/><circle cx="50" cy="66" r="3.5" fill="#b45309"/></svg>',
        401 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#003189" stroke-width="5" fill="#e8eaf6"/><rect x="38" y="42" width="24" height="22" rx="3" fill="#003189"/><path d="M42 42V36 a8 8 0 0 1 16 0v6" stroke="#003189" stroke-width="3" fill="none" stroke-linecap="round"/><circle cx="50" cy="52" r="2.5" fill="#e8eaf6"/></svg>',
        500 => '<svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="42" stroke="#c0392b" stroke-width="5" fill="#fde8e8"/><path d="M32 38 Q40 32 50 38 Q60 44 68 38" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M32 56 Q40 50 50 56 Q60 62 68 56" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M35 72 Q50 64 65 72" stroke="#c0392b" stroke-width="3" fill="none" stroke-linecap="round"/></svg>',
    ];
    $icon = $icons[$code] ?? $icons[500];

    $hint_html = '';
    if (!empty($hint)) {
        $hint_html = '<div class="error-hint"><strong>Que faire ?</strong>' . nl2br(h($hint)) . '</div>';
    }

    $user = '';
    if (function_exists('get_auth_user')) {
        try { $user = get_auth_user(); } catch (\Throwable $e) { $user = ''; }
    }

    $bandeau_links = '';
    if (!empty($user)) {
        $bandeau_links = '<span>Connecté en tant que : <strong>' . h($user) . '</strong></span>
    <span><a href="index.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">Accueil</a></span>';
    }

    // Charger le CSS partagé
    $css = '';
    $style_file = __DIR__ . '/style.php';
    if (file_exists($style_file)) {
        ob_start();
        require $style_file;
        $css = ob_get_clean();
    }
    // Si le require n'a rien produit (style.php est un fragment <style>…</style>), fallback minimal
    if (empty(trim(strip_tags($css)))) {
        $css = '<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{font-family:"Marianne",Arial,sans-serif;background:#f5f5fe;color:#1e1e1e}.bandeau{background:#003189;color:#fff;padding:.75rem 2rem;font-size:.85rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}.bandeau a{color:#b3c8f0;font-size:.8rem;text-decoration:none}.btn{padding:.5rem 1rem;border:none;border-radius:3px;font-size:.85rem;font-family:inherit;cursor:pointer;text-decoration:none;display:inline-block}.btn-primary{background:#003189;color:#fff}.btn-primary:hover{background:#002270}.skip-link{position:absolute;left:-9999px;top:0;background:#003189;color:#fff;padding:.5rem 1rem;z-index:9999}.skip-link:focus{left:0}.error-page{display:flex;min-height:calc(100vh - 120px);align-items:center;justify-content:center;padding:2rem 1rem}.error-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:3rem 2.5rem;max-width:560px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)}.error-card .error-code{font-size:5rem;font-weight:900;line-height:1;margin-bottom:.25rem;letter-spacing:-2px}.error-card .error-code.code-403{color:#c0392b}.error-card .error-code.code-404{color:#003189}.error-card .error-code.code-400{color:#b45309}.error-card .error-code.code-401{color:#003189}.error-card .error-code.code-500{color:#c0392b}.error-card .error-illustration{margin-bottom:1.25rem}.error-card .error-illustration svg{width:100px;height:100px}.error-card h1{font-size:1.35rem;color:#1e1e1e;margin-bottom:.75rem;border:none;padding:0}.error-card .error-message{color:#555;font-size:.95rem;line-height:1.6;margin-bottom:1.25rem}.error-card .error-hint{font-size:.85rem;color:#666;background:#f5f5fe;border:1px solid #e0e0f0;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.5rem;text-align:left;line-height:1.55}.error-card .error-hint strong{color:#333;display:block;margin-bottom:.35rem}.error-card .error-actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap}.error-card .error-stamp{margin-top:1.5rem;padding-top:1rem;border-top:1px solid #eee;font-size:.75rem;color:#aaa}</style>';
    }

    die('<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>' . h($title) . ' — ' . h(get_app_name()) . '</title>
  ' . render_favicon() . '
  ' . $css . '
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l\'Économie, de l\'Emploi, du Travail et des Solidarités
  ' . $bandeau_links . '
</div>
<div class="error-page" id="main-content">
  <div class="error-card">
    <div class="error-illustration">' . $icon . '</div>
    <div class="error-code code-' . $code . '">' . $code . '</div>
    <h1>' . h($title) . '</h1>
    <p class="error-message">' . $message . '</p>
    ' . $hint_html . '
    <div class="error-actions">
      <a href="' . h($back_url) . '" class="btn btn-primary">Retour à l\'accueil</a>
    </div>
    <div class="error-stamp">' . h(get_app_name()) . '</div>
  </div>
</div>
' . (function_exists('render_footer') ? render_footer() : '') . '
</body>
</html>');
}

// ── APP NAME & FAVICON (from DB) ───────────────────────────
function get_app_name(): string {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = get_setting('app_name', 'CircuitDémat');
    return $cache;
}

function render_favicon(): string {
    $svg = get_setting('app_favicon', '');
    if (!empty($svg)) {
        return '<link rel="icon" href="data:image/svg+xml,' . h($svg) . '">';
    }
    // Favicon par défaut : losange bleu avec F
    return '<link rel="icon" href="data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><rect width=\'100\' height=\'100\' rx=\'20\' fill=\'%231E40AF\'/><text x=\'50\' y=\'72\' font-size=\'60\' text-anchor=\'middle\' fill=\'white\' font-family=\'Arial\' font-weight=\'bold\'>F</text></svg>">';
}

// ── SETTINGS ─────────────────────────────────────────────────
function get_setting(string $key, string $default = ''): string {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function set_setting(string $key, string $value, string $updated_by = ''): void {
    $pdo = get_pdo();
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at, updated_by) VALUES (?, ?, datetime('now'), ?)")
        ->execute([$key, $value, $updated_by]);
}

// ── VÉRIFICATION EMAIL ─────────────────────────────────────────

/**
 * Vérifie qu'une adresse email existe dans l'Active Directory via LDAP.
 *
 * Prérequis : extension PHP ldap activée, accès réseau vers le serveur AD.
 * Connexion en lecture seule (bind anonyme ou compte de service dédié).
 *
 * @param string $email Adresse email à vérifier
 * @return array ['ok' => bool, 'method' => string, 'detail' => string]
 */
function verify_email_ldap(string $email): array {
    // Vérifier que l'extension LDAP est disponible
    if (!function_exists('ldap_connect')) {
        return ['ok' => false, 'method' => 'ldap', 'detail' => 'Extension PHP ldap non disponible'];
    }

    $host     = get_setting('ldap_host', '');
    $port     = (int)get_setting('ldap_port', '389');
    $base_dn  = get_setting('ldap_base_dn', '');
    $bind_dn  = get_setting('ldap_bind_dn', '');
    $bind_pass= get_setting('ldap_bind_pass', '');
    $filter   = get_setting('ldap_filter', '(mail={email})');

    if (empty($host) || empty($base_dn)) {
        return ['ok' => false, 'method' => 'ldap', 'detail' => 'Configuration LDAP incomplète (hôte ou base DN manquant)'];
    }

    // Connexion LDAP
    $ldap_uri = (strpos($host, '://') !== false) ? $host : 'ldap://' . $host;
    $conn = @ldap_connect($ldap_uri, $port);
    if (!$conn) {
        return ['ok' => false, 'method' => 'ldap', 'detail' => 'Impossible de se connecter au serveur LDAP ' . $host];
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
    ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 5);

    // Bind — anonyme si aucun bind_dn configuré, sinon avec le compte de service
    if (!empty($bind_dn)) {
        $bind = @ldap_bind($conn, $bind_dn, $bind_pass);
    } else {
        $bind = @ldap_bind($conn); // Bind anonyme
    }

    if (!$bind) {
        $errno = ldap_errno($conn);
        $error = ldap_err2str($errno);
        @ldap_close($conn);
        return ['ok' => false, 'method' => 'ldap', 'detail' => "Échec d'authentification LDAP (code $errno : $error)"];
    }

    // Recherche de l'email dans l'annuaire
    $search_filter = str_replace('{email}', $email, $filter);
    // Échapper les caractères spéciaux LDAP dans l'email pour la sécurité
    $search_filter = str_replace($email, ldap_escape($email, '', LDAP_ESCAPE_FILTER), $search_filter);

    $search = @ldap_search($conn, $base_dn, $search_filter, ['mail', 'cn', 'distinguishedName']);
    if (!$search) {
        $errno = ldap_errno($conn);
        @ldap_close($conn);
        return ['ok' => false, 'method' => 'ldap', 'detail' => "Erreur de recherche LDAP (code $errno)"];
    }

    $entries = ldap_get_entries($conn, $search);
    @ldap_close($conn);

    $count = (int)($entries['count'] ?? 0);
    if ($count > 0) {
        $cn = $entries[0]['cn'][0] ?? '(nom inconnu)';
        return ['ok' => true, 'method' => 'ldap', 'detail' => "Trouvé dans l'AD : $cn"];
    }

    return ['ok' => false, 'method' => 'ldap', 'detail' => "Adresse $email introuvable dans l'annuaire Active Directory"];
}

/**
 * Vérifie qu'une adresse email existe via une probe SMTP (RCPT TO).
 *
 * Ouvre une connexion SMTP au serveur configuré, envoie HELO/MAIL FROM/RCPT TO
 * et vérifie si le serveur accepte le destinataire. Se déconnecte proprement
 * sans envoyer de mail (QUIT avant DATA).
 *
 * ⚠️ Attention : certains serveurs SMTP acceptent tous les RCPT TO (catch-all).
 *    Cette méthode est un indicateur, pas une garantie absolue.
 *
 * @param string $email Adresse email à vérifier
 * @return array ['ok' => bool, 'method' => string, 'detail' => string]
 */
function verify_email_smtp(string $email): array {
    $smtp_host = get_setting('smtp_host', SMTP_HOST);
    $smtp_port = (int)get_setting('smtp_port', (string)SMTP_PORT);
    $smtp_from = get_setting('smtp_from', SMTP_FROM);
    $smtp_secure = get_setting('smtp_secure', '');

    if (empty($smtp_host)) {
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Aucun serveur SMTP configuré'];
    }

    // Vérifier que l'extension sockets est disponible
    if (!function_exists('fsockopen')) {
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Extension PHP sockets non disponible'];
    }

    // Connexion SMTP avec timeout
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    // Pour TLS, on se connecte d'abord en clair puis on fait STARTTLS
    $conn = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, $timeout);
    if (!$conn) {
        return ['ok' => false, 'method' => 'smtp', 'detail' => "Impossible de se connecter à $smtp_host:$smtp_port ($errstr)"];
    }

    stream_set_timeout($conn, $timeout);

    // Fonction utilitaire pour lire une réponse SMTP
    $read_smtp = function() use ($conn): string {
        $response = '';
        while ($line = fgets($conn, 512)) {
            $response .= $line;
            // Les réponses multilignes ont un '-' après le code, la dernière ligne a un espace
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    };

    // Fonction utilitaire pour envoyer une commande SMTP
    $send_smtp = function(string $cmd) use ($conn): void {
        fwrite($conn, $cmd . "\r\n");
    };

    // Bannière de bienvenue
    $banner = $read_smtp();
    if (!str_starts_with($banner, '220')) {
        fclose($conn);
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'Bannière SMTP invalide : ' . trim($banner)];
    }

    // HELO
    $send_smtp('HELO ' . gethostname());
    $resp = $read_smtp();
    if (!str_starts_with($resp, '250')) {
        fclose($conn);
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'HELO rejeté : ' . trim($resp)];
    }

    // STARTTLS si configuré
    if ($smtp_secure === 'tls') {
        $send_smtp('STARTTLS');
        $resp = $read_smtp();
        if (!str_starts_with($resp, '220')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'STARTTLS rejeté : ' . trim($resp)];
        }
        if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'Échec de la négociation TLS'];
        }
        // Retour au HELO après STARTTLS
        $send_smtp('EHLO ' . gethostname());
        $resp = $read_smtp();
        if (!str_starts_with($resp, '250')) {
            fclose($conn);
            return ['ok' => false, 'method' => 'smtp', 'detail' => 'EHLO après STARTTLS rejeté : ' . trim($resp)];
        }
    }

    // MAIL FROM
    $send_smtp('MAIL FROM:<' . $smtp_from . '>');
    $resp = $read_smtp();
    if (!str_starts_with($resp, '250')) {
        $send_smtp('QUIT');
        $read_smtp();
        fclose($conn);
        return ['ok' => false, 'method' => 'smtp', 'detail' => 'MAIL FROM rejeté : ' . trim($resp)];
    }

    // RCPT TO — la vérification clé
    $send_smtp('RCPT TO:<' . $email . '>');
    $resp = $read_smtp();

    // QUIT proprement
    $send_smtp('QUIT');
    $read_smtp();
    fclose($conn);

    $code = substr($resp, 0, 3);
    if ($code === '250') {
        return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée par le serveur SMTP'];
    }

    if ($code === '251') {
        // 251 = User not local; will forward to <forward-path>
        return ['ok' => true, 'method' => 'smtp', 'detail' => 'Adresse acceptée (transfert) par le serveur SMTP'];
    }

    return ['ok' => false, 'method' => 'smtp', 'detail' => 'Adresse rejetée par le serveur SMTP : ' . trim($resp)];
}

/**
 * Vérifie une adresse email selon le mode configuré (LDAP, SMTP ou aucun).
 *
 * @param string $email Adresse email à vérifier
 * @return array ['ok' => bool, 'method' => string, 'detail' => string]
 */
function verify_email(string $email): array {
    $mode = get_setting('email_verify_mode', 'none');

    // Validation basique du format email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'method' => 'format', 'detail' => 'Format d\'email invalide : ' . $email];
    }

    if ($mode === 'none') {
        return ['ok' => true, 'method' => 'none', 'detail' => 'Aucune vérification configurée'];
    }

    if ($mode === 'ldap') {
        return verify_email_ldap($email);
    }

    if ($mode === 'smtp') {
        return verify_email_smtp($email);
    }

    // Mode inconnu = pas de vérification
    return ['ok' => true, 'method' => 'none', 'detail' => 'Mode de vérification inconnu : ' . $mode];
}

/**
 * Teste la vérification email avec une adresse donnée (pour la page admin).
 * Retourne le résultat détaillé pour affichage.
 */
function test_email_verification(string $email): array {
    $mode = get_setting('email_verify_mode', 'none');

    $results = [
        'email' => $email,
        'mode'  => $mode,
        'format_valid' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
    ];

    if ($mode === 'ldap') {
        $results['ldap'] = verify_email_ldap($email);
    } elseif ($mode === 'smtp') {
        $results['smtp'] = verify_email_smtp($email);
    } elseif ($mode === 'both') {
        // Mode both : LDAP en priorité, SMTP en fallback
        $ldap_result = verify_email_ldap($email);
        $results['ldap'] = $ldap_result;
        if (!$ldap_result['ok']) {
            $results['smtp'] = verify_email_smtp($email);
        }
    }

    $results['verify'] = verify_email($email);
    return $results;
}

// ── MAIL ─────────────────────────────────────────────────────
function send_mail(string $to, string $subject, string $body): bool {
    // Mode test : intercepter les mails sans les envoyer
    if (TEST_MODE) {
        $GLOBALS['_test_mails'][] = [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'time'    => date('Y-m-d H:i:s'),
        ];
        return true;
    }

    // Mode dry-run : aucun email réel n'est envoyé, tout est journalisé
    $dry_run = get_setting('mail_dry_run', '0') === '1';
    if ($dry_run) {
        error_log("send_mail() DRY-RUN — destinataire: $to, sujet: $subject");
        app_log('mail_dry_run', 'mail:' . $to, "Email intercepté (dry-run) — Sujet : $subject");
        return true; // Retourne true pour ne pas bloquer le workflow
    }

    // Vérification de l'adresse email avant envoi
    $verify_mode = get_setting('email_verify_mode', 'none');
    if ($verify_mode !== 'none') {
        $verification = verify_email($to);
        if (!$verification['ok']) {
            error_log("send_mail() BLOQUÉ — email non vérifié : $to — " . $verification['detail']);
            app_log('mail_blocked', 'mail:' . $to, "Email bloqué (vérification échouée) — " . $verification['detail'] . " — Sujet : $subject");
            return false;
        }
    }

    // Sécurité CLI : ne jamais envoyer d'emails réels depuis un contexte CLI
    // (scripts de test, remind.php, alert_check.php utilisent un envoi explicite)
    if (php_sapi_name() === 'cli' && !defined('CLI_MAIL_ALLOWED')) {
        error_log("send_mail() bloqué en CLI sans CLI_MAIL_ALLOWED (destinataire: $to)");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host     = get_setting('smtp_host', SMTP_HOST);
        $mail->Port     = (int)get_setting('smtp_port', (string)SMTP_PORT);
        $mail->SMTPAuth = get_setting('smtp_auth', '0') === '1';
        if ($mail->SMTPAuth) {
            $mail->Username = get_setting('smtp_user', '');
            $mail->Password = get_setting('smtp_pass', '');
        }
        $secure = get_setting('smtp_secure', '');
        if ($secure === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($secure === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->CharSet  = 'UTF-8';
        $mail->setFrom(get_setting('smtp_from', SMTP_FROM), get_setting('smtp_from_name', SMTP_FROM_NAME));
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject  = $subject;
        $mail->Body     = $body;
        $mail->send();
        app_log('mail_sent', 'mail:' . $to, "Email envoyé — Sujet : $subject");
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        app_log('mail_error', 'mail:' . $to, "Échec envoi — " . $mail->ErrorInfo . " — Sujet : $subject");
        return false;
    }
}

function build_mail_html(array $submission, string $step_label, string $token): string {
    $data         = json_decode($submission['data'], true);
    $nom          = h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? ''));
    $form_label   = h($submission['form_label'] ?? '');
    $validate_url = BASE_URL . '/validate.php?token=' . urlencode($token);

    $lignes = '';
    foreach ($data as $k => $v) {
        if (empty($v) || $v === '0' || $k === 'validations') continue;
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        $label  = ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)));
        $valeur = $v === '1' ? '✓' : h((string)$v);
        $lignes .= "<tr><td style='padding:5px 8px;font-weight:bold;color:#555;'>{$label}</td><td style='padding:5px 8px;'>{$valeur}</td></tr>";
    }

    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">' . $form_label . ' — Action requise</h2>
  <p style="color:#555;margin-bottom:16px;">Étape : <strong>' . h($step_label) . '</strong></p>
  <table style="border-collapse:collapse;width:100%;margin-bottom:24px;">' . $lignes . '</table>
  <a href="' . $validate_url . '" style="background:#003189;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">
    ✓ Marquer comme effectué
  </a>
  <p style="font-size:12px;color:#999;margin-top:24px;">Lien à usage unique — ' . h(get_setting('smtp_from', SMTP_FROM)) . '</p>
</body></html>';
}

// ── MOTEUR WORKFLOW ───────────────────────────────────────────

/**
 * Déclenche la prochaine étape d'une soumission.
 * Appelé à la création ET après chaque validation.
 *
 * Logique :
 *  - Récupère toutes les étapes du formulaire triées par ordre
 *  - Trouve le plus petit ordre sans tokens générés = prochaine étape
 *  - Si ordre précédent non terminé (séquentiel) → on attend
 *  - Génère les tokens pour tous les destinataires de l'étape courante
 *  - Si plus aucune étape → soumission close
 */
function advance_workflow(string $submission_id): void {
    $pdo = get_pdo();

    $sub = $pdo->prepare("
        SELECT s.*, f.label as form_label
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE s.id = ?
    ");
    $sub->execute([$submission_id]);
    $submission = $sub->fetch(PDO::FETCH_ASSOC);
    if (!$submission || $submission['closed_at']) return;

    // Toutes les étapes actives du formulaire
    $steps = $pdo->prepare("
        SELECT st.*, GROUP_CONCAT(sr.email, '|') as emails
        FROM steps st
        JOIN step_recipients sr ON sr.step_id = st.id
        WHERE st.form_id = ? AND st.actif = 1
        GROUP BY st.id
        ORDER BY st.ordre ASC, st.id ASC
    ");
    $steps->execute([$submission['form_id']]);
    $all_steps = $steps->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_steps)) return;

    // Tokens déjà générés pour cette soumission
    $existing = $pdo->prepare("SELECT step_id, done_at FROM tokens WHERE submission_id = ?");
    $existing->execute([$submission_id]);
    $tokens_by_step = [];
    foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $tokens_by_step[$t['step_id']][] = $t['done_at'];
    }

    // Groupe les étapes par ordre
    $by_ordre = [];
    foreach ($all_steps as $step) {
        $by_ordre[$step['ordre']][] = $step;
    }
    ksort($by_ordre);

    foreach ($by_ordre as $ordre => $groupe) {
        $step_ids    = array_column($groupe, 'id');
        $all_started = count(array_intersect($step_ids, array_keys($tokens_by_step))) === count($step_ids);
        $all_done    = $all_started && array_reduce($step_ids, function($carry, $sid) use ($tokens_by_step) {
            if (!$carry) return false;
            if (!isset($tokens_by_step[$sid])) return false;
            foreach ($tokens_by_step[$sid] as $done) {
                if (empty($done)) return false;
            }
            return true;
        }, true);

        if (!$all_started) {
            // Cette étape n'a pas encore de tokens → on la démarre (parallèle dans le groupe)
            $now = date('Y-m-d H:i:s');
            $expire_days = (int)get_setting('token_expire_days', '30');
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
            foreach ($groupe as $step) {
                $emails = explode('|', $step['emails']);
                foreach ($emails as $email) {
                    $token = generate_token();
                    $token_row_id = generate_uuid();
                    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$token_row_id, $submission_id, $step['id'], $email, $token, $now, $expires_at]);
                    $subject = '[Action requise] ' . ($submission['form_label'] ?? '') . ' — ' . $step['label'];
                    $mail_sent = send_mail($email, $subject, build_mail_html($submission, $step['label'], $token));
                    if (!$mail_sent) {
                        error_log("Workflow: mail failed for token $token to $email");
                    }
                }
            }
            return; // On attend que cette étape soit terminée avant de passer à la suivante
        }

        if (!$all_done) {
            return; // Étape en cours, on attend
        }

        // Étape terminée → on continue la boucle vers l'ordre suivant
    }

    // Toutes les étapes sont terminées → on close et on notifie l'agent
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'valide' WHERE id = ?")
        ->execute([$now, $submission_id]);

    // Notification de validation finale a l'agent
    $agent_email = $submission['submitted_by'] ?? '';
    if (!empty($agent_email) && filter_var($agent_email, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Demande validée — ' . ($submission['form_label'] ?? get_app_name());
        $body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#1a6b3c;">✓ Demande validée</h2>
  <p>Votre demande <strong>' . h($submission['form_label'] ?? '') . '</strong> a été <strong>validée</strong> par l\'ensemble des validateurs.</p>
  <p>Le processus de workflow est désormais terminé.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">' . h(get_app_name()) . ' — ' . h(get_setting('smtp_from', SMTP_FROM)) . '</p>
</body></html>';
        send_mail($agent_email, $subject, $body);
    }

    app_log('workflow_complete', 'submission:' . $submission_id, 'Formulaire ' . ($submission['form_label'] ?? '') . ' validé', $agent_email);

    // Webhook notification
    send_webhook('workflow_complete', ['submission_id' => $submission_id, 'form_label' => $submission['form_label'] ?? '', 'submitted_by' => $submission['submitted_by'] ?? '']);
}

/**
 * Appelé par validate.php quand un token est validé.
 * Met à jour done_at puis avance le workflow.
 */
function validate_token(string $token, string $action = 'valider', string $comment = ''): array {
    $pdo = get_pdo();

    $row = $pdo->prepare("
        SELECT t.*, st.label as step_label, s.form_id,
               f.label as form_label, s.data, s.closed_at, s.status
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        JOIN submissions s ON s.id = t.submission_id
        JOIN forms f ON f.id = s.form_id
        WHERE t.token = ?
    ");
    $row->execute([$token]);
    $t = $row->fetch(PDO::FETCH_ASSOC);

    if (!$t)             return ['status' => 'invalid'];
    if ($t['done_at'])   return ['status' => 'already_done', 'data' => $t];
    if ($t['closed_at']) return ['status' => 'closed',       'data' => $t];

    // Vérifier si le token a expiré
    if (!empty($t['expires_at'])) {
        $exp_ts = strtotime($t['expires_at']);
        if ($exp_ts !== false && $exp_ts < time()) {
            return ['status' => 'expired', 'data' => $t];
        }
    }

    // Récupérer les données actuelles
    $data = json_decode($t['data'], true);
    
    // Ajouter la validation au tableau des validations
    $validation = [
        'step_label' => $t['step_label'],
        'email' => $t['email'],
        'action' => $action,
        'commentaire' => $comment,
        'date' => date('Y-m-d H:i:s')
    ];
    
    // Initialiser le tableau des validations s'il n'existe pas
    if (!isset($data['validations'])) {
        $data['validations'] = [];
    }
    
    // Ajouter la nouvelle validation
    $data['validations'][] = $validation;
    
    // Mettre à jour les données avec les validations
    $updated_data = json_encode($data);
    
    if ($action === 'refuser') {
        // Pour le refus : mettre à jour done_at et fermer la soumission avec status refuse
        $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ?")
            ->execute([date('Y-m-d H:i:s'), $token]);
            
        // Fermer la soumission avec le statut refuse
        $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'refuse' WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $t['submission_id']]);

        // Notifier l'agent du refus
        $agent_email = $t['submitted_by'] ?? '';
        if (!empty($agent_email) && filter_var($agent_email, FILTER_VALIDATE_EMAIL)) {
            $refuse_subject = 'Demande refusée — ' . ($t['form_label'] ?? get_app_name());
            $refuse_body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#c0392b;">Demande refusée</h2>
  <p>Votre demande <strong>' . h($t['form_label'] ?? '') . '</strong> a été refusée à l\'étape <strong>' . h($t['step_label']) . '</strong>.</p>
  ' . (!empty($comment) ? '<p><strong>Motif :</strong> ' . h($comment) . '</p>' : '') . '
  <p style="font-size:12px;color:#999;margin-top:24px;">' . h(get_app_name()) . ' — ' . h(get_setting('smtp_from', SMTP_FROM)) . '</p>
</body></html>';
            send_mail($agent_email, $refuse_subject, $refuse_body);
        }
    } else {
        // Pour la validation : comportement normal
        $pdo->prepare("UPDATE tokens SET done_at = ? WHERE token = ?")
            ->execute([date('Y-m-d H:i:s'), $token]);

        advance_workflow($t['submission_id']);
    }
    
    // Mettre à jour les données de la soumission
    $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
        ->execute([$updated_data, $t['submission_id']]);

    // Webhook notification
    send_webhook('token_validated', ['submission_id' => $t['submission_id'], 'step_label' => $t['step_label'], 'email' => $t['email'], 'action' => $action]);

    $t['done_at'] = date('Y-m-d H:i:s');
    return ['status' => 'ok', 'data' => $t];
}

// ── ACTIVE SUBMISSIONS CHECK ───────────────────────────────────

/**
 * Vérifie si un formulaire a des soumissions actives (en_cours)
 */
function has_active_submissions(string $form_id): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
    $stmt->execute([$form_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Vérifie si une étape a des soumissions actives (tokens en cours sur cette étape)
 */
function has_active_step_submissions(string $step_id): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT t.submission_id)
        FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        WHERE t.step_id = ? AND t.done_at IS NULL AND s.status = 'en_cours'
    ");
    $stmt->execute([$step_id]);
    return (int)$stmt->fetchColumn();
}

// ── ADMIN FUNCTIONS ───────────────────────────────────────────

/**
 * Traite une demande d'accès admin
 */
function process_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    // Vérifie si l'utilisateur est déjà admin
    if (is_admin_user()) {
        return true;
    }
    
    // Vérifie si une demande est déjà en attente
    $stmt = $pdo->prepare("SELECT 1 FROM admin_requests WHERE email = ? AND status = 'pending'");
    $stmt->execute([$email]);
    if ($stmt->fetch() !== false) {
        return false;
    }
    
    // Génère un token pour la demande
    $token = bin2hex(random_bytes(32));
    
    // Insère la demande dans la base de données
    try {
        $ar_id = generate_uuid();
        $stmt = $pdo->prepare("INSERT INTO admin_requests (id, email, requested_at, status, token) VALUES (?, ?, ?, 'pending', ?)");
        $stmt->execute([$ar_id, $email, date('Y-m-d H:i:s'), $token]);
        
        app_log('admin_request', 'admin:' . $email, 'Demande d\'accès admin', $email);

        // Envoie un email à l'admin principal pour approbation
        $approve_url = BASE_URL . '/admin_access.php?action=approve&token=' . $token;
        $reject_url = BASE_URL . '/admin_access.php?action=reject&token=' . $token;
        $subject = 'Demande d\'accès admin - ' . get_app_name();
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin</h2>
    <p>Un utilisateur a demandé l\'accès admin au back office du workflow :</p>
    <p><strong>Utilisateur :</strong> ' . h($email) . '</p>
    <p><strong>Date :</strong> ' . date('d/m/Y H:i:s') . '</p>
    <p><a href="' . $approve_url . '" style="background:#1a6b3c;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px;">Approuver</a>
    <a href="' . $reject_url . '" style="background:#c0392b;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;">Refuser</a></p>
</body>
</html>';
        
        send_mail(get_admin_email(), $subject, $body);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de la demande d\'accès admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Approve an admin request
 */
function approve_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Met à jour la demande
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'approved' WHERE email = ?");
        $stmt->execute([$email]);
        
        // Ajoute l'utilisateur comme administrateur
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, ?)");
        $stmt->execute([generate_uuid(), $email, date('Y-m-d H:i:s')]);
        
        // Envoie un email de confirmation
        $subject = 'Accès admin approuvé - ' . get_app_name();
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Accès admin approuvé</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été approuvée.</p>
    <p>Vous pouvez maintenant accéder au back office en cliquant sur le lien ci-dessous :</p>
    <p><a href="' . BASE_URL . '/admin_access.php">Accéder au back office</a></p>
</body>
</html>';
        
        send_mail($email, $subject, $body);
        app_log('admin_approve', 'admin:' . $email, 'Accès admin approuvé');
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de l\'approbation de la demande admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Reject an admin request
 */
function reject_admin_request(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Met à jour la demande
        $stmt = $pdo->prepare("UPDATE admin_requests SET status = 'rejected' WHERE email = ?");
        $stmt->execute([$email]);
        
        // Envoie un email de refus
        $subject = 'Demande d\'accès admin refusée - ' . get_app_name();
        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin refusée</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été refusée.</p>
</body>
</html>';
        
        send_mail($email, $subject, $body);
        app_log('admin_reject', 'admin:' . $email, 'Accès admin refusé');
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors du refus de la demande admin : ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove an admin
 */
function remove_admin(string $email): bool {
    $pdo = get_pdo();
    
    // Ne peut pas supprimer l'admin principal
    if ($email === get_admin_email()) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        app_log('admin_remove', 'admin:' . $email, 'Admin supprimé', $email);
        return true;
    } catch (Exception $e) {
        error_log('Erreur lors de la suppression d\'un admin : ' . $e->getMessage());
        return false;
    }
}

// ── AUDIT LOG ────────────────────────────────────────────────

/**
 * Enregistre une action dans le journal d'audit
 *
 * @param string $action  Type d'action (ex: 'form_create', 'admin_remove', 'settings_update')
 * @param string $target  Cible de l'action (ex: 'form:3', 'submission:42')
 * @param string $detail  Description lisible de l'action
 * @param string $actor   Acteur (email), si vide = utilisateur connecté
 */
function app_log(string $action, string $target = '', string $detail = '', string $actor = ''): void {
    try {
        $pdo = get_pdo();
        if (empty($actor)) {
            $actor = get_auth_user();
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'CLI');
        $pdo->prepare("INSERT INTO audit_log (id, action, target, detail, actor, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))")
            ->execute([generate_uuid(), $action, $target, $detail, $actor, $ip]);
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

/**
 * Récupère les entrées du journal d'audit
 */
function get_audit_logs(int $limit = 100, string $action_filter = ''): array {
    $pdo = get_pdo();
    if ($action_filter) {
        $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE action = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$action_filter, $limit]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── EXPORT CSV ───────────────────────────────────────────────

/**
 * Exporte les soumissions au format CSV et force le téléchargement
 *
 * @param PDO   $pdo     Connexion base de données
 * @param array $options Filtres optionnels ['form_id' => int, 'status' => string]
 */
/**
 * Récupère les mails interceptés en mode test
 */
function get_test_mails(): array {
    return $GLOBALS['_test_mails'] ?? [];
}

/**
 * Réinitialise la file d'attente des mails test
 */
function reset_test_mails(): void {
    $GLOBALS['_test_mails'] = [];
}

/**
 * Réponse JSON pour le mode test (à appeler dans les pages au lieu de die/redirect)
 * En mode test, les pages doivent appeler test_json_response() avant tout die()/exit()/header('Location:')
 * pour renvoyer un JSON structuré exploitable par les tests.
 */
function test_json_response(array $data): void {
    if (!TEST_MODE) return;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['_test_mode' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── EXPORT CSV ───────────────────────────────────────────────

function export_csv(PDO $pdo, array $options = []): void {
    $where = ['1=1'];
    $params = [];
    if (!empty($options['form_id'])) {
        $where[] = 's.form_id = ?';
        $params[] = $options['form_id'];
    }
    if (!empty($options['status'])) {
        $where[] = 's.status = ?';
        $params[] = $options['status'];
    }
    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.closed_at, s.status,
               f.label as form_label, f.slug as form_slug
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE $where_sql
        ORDER BY s.submitted_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collecter toutes les clés de données pour les colonnes
    $all_keys = [];
    foreach ($rows as $row) {
        $data = json_decode($row['data'], true) ?: [];
        foreach (array_keys($data) as $k) {
            if ($k !== 'validations' && !in_array($k, $all_keys)) {
                $all_keys[] = $k;
            }
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="export_submissions_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM pour Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // En-tête fixe
    $headers = array_merge(['ID', 'Formulaire', 'Agent', 'Statut', 'Soumis le', 'Clôturé le'], $all_keys);
    fputcsv($out, $headers, ';', '"', '\\');

    foreach ($rows as $row) {
        $data = json_decode($row['data'], true) ?: [];
        $line = [
            $row['id'],
            $row['form_label'],
            $row['submitted_by'],
            $row['status'],
            $row['submitted_at'],
            $row['closed_at'] ?? '',
        ];
        foreach ($all_keys as $k) {
            $val = $data[$k] ?? '';
            if ($val === '1') $val = 'Oui';
            elseif ($val === '0') $val = 'Non';
            elseif (is_array($val)) $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            $line[] = $val;
        }
        fputcsv($out, $line, ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// ── TOKEN REGENERATION ───────────────────────────────────────

/**
 * Régénère un token expiré pour un validateur (admin uniquement)
 * Invalide l'ancien token et crée un nouveau avec une nouvelle date d'expiration
 *
 * @param string $old_token_id ID de l'ancien token
 * @return array ['success' => bool, 'message' => string]
 */
function regenerate_token(string $old_token_id): array {
    $pdo = get_pdo();

    // Récupérer l'ancien token
    $stmt = $pdo->prepare("
        SELECT t.*, s.status as sub_status
        FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        WHERE t.id = ?
    ");
    $stmt->execute([$old_token_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        return ['success' => false, 'message' => 'Token introuvable.'];
    }
    if ($old['done_at']) {
        return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
    }
    if ($old['sub_status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
    }

    // Marquer l'ancien token comme traité (invalidé)
    $pdo->prepare("UPDATE tokens SET done_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $old_token_id]);

    // Créer un nouveau token
    $new_token = generate_token();
    $expire_days = (int)get_setting('token_expire_days', '30');
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
    $now = date('Y-m-d H:i:s');

    $new_token_row_id = generate_uuid();
    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$new_token_row_id, $old['submission_id'], $old['step_id'], $old['email'], $new_token, $now, $expires_at]);

    // Envoyer le nouveau lien par email
    $sub_stmt = $pdo->prepare("
        SELECT s.*, f.label as form_label FROM submissions s
        JOIN forms f ON f.id = s.form_id WHERE s.id = ?
    ");
    $sub_stmt->execute([$old['submission_id']]);
    $submission = $sub_stmt->fetch(PDO::FETCH_ASSOC);

    $step_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
    $step_stmt->execute([$old['step_id']]);
    $step = $step_stmt->fetch(PDO::FETCH_ASSOC);

    if ($submission && $step) {
        $subject = '[Renvoi] ' . ($submission['form_label'] ?? '') . ' — ' . ($step['label'] ?? '');
        $mail_sent = send_mail($old['email'], $subject, build_mail_html($submission, $step['label'], $new_token));
    }

    app_log('token_regenerate', 'token:' . $old_token_id, 'Token régénéré pour ' . $old['email'] . ', nouveau token créé');

    return [
        'success' => true,
        'message' => 'Nouveau lien de validation envoyé à ' . $old['email'],
    ];
}

// ── SUBMISSION CANCEL ────────────────────────────────────────

/**
 * Annule une soumission en cours
 *
 * @param string $submission_id ID de la soumission
 * @param string $cancelled_by Email de l'utilisateur qui annule
 * @return array ['success' => bool, 'message' => string]
 */
function cancel_submission(string $submission_id, string $cancelled_by = ''): array {
    $pdo = get_pdo();

    $stmt = $pdo->prepare("SELECT s.*, f.label as form_label FROM submissions s JOIN forms f ON f.id = s.form_id WHERE s.id = ?");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        return ['success' => false, 'message' => 'Soumission introuvable.'];
    }
    if ($submission['status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'Seules les soumissions en cours peuvent être annulées.'];
    }

    // Fermer la soumission avec le statut 'refuse' (annulé)
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE submissions SET closed_at = ?, status = 'refuse' WHERE id = ?")
        ->execute([$now, $submission_id]);

    // Marquer tous les tokens non traités comme done (annulés)
    $pdo->prepare("UPDATE tokens SET done_at = ? WHERE submission_id = ? AND done_at IS NULL")
        ->execute([$now, $submission_id]);

    // Ajouter l'annulation dans les validations
    $data = json_decode($submission['data'], true) ?: [];
    if (!isset($data['validations'])) $data['validations'] = [];
    $data['validations'][] = [
        'step_label' => 'Annulation',
        'email' => $cancelled_by ?: 'system',
        'action' => 'refuser',
        'commentaire' => 'Soumission annulée',
        'date' => $now,
    ];
    $pdo->prepare("UPDATE submissions SET data = ? WHERE id = ?")
        ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $submission_id]);

    // Notifier l'agent
    $agent_email = $submission['submitted_by'] ?? '';
    if (!empty($agent_email) && filter_var($agent_email, FILTER_VALIDATE_EMAIL)) {
        $subject = 'Demande annulée — ' . ($submission['form_label'] ?? get_app_name());
        $body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#b45309;">Demande annulée</h2>
  <p>Votre demande <strong>' . h($submission['form_label'] ?? '') . '</strong> a été annulée.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">' . h(get_app_name()) . '</p>
</body></html>';
        send_mail($agent_email, $subject, $body);
    }

    app_log('submission_cancel', 'submission:' . $submission_id, 'Soumission annulée', $cancelled_by);

    // Webhook notification
    send_webhook('submission_cancelled', ['submission_id' => $submission_id, 'form_label' => $submission['form_label'] ?? '', 'cancelled_by' => $cancelled_by]);

    return ['success' => true, 'message' => 'Soumission annulée avec succès.'];
}

// ── MANUAL REMINDER ──────────────────────────────────────────

/**
 * Envoie un rappel manuel pour un token en attente
 * Contrairement a regenerate_token, celui-ci ne modifie pas le token existant
 * Il envoie simplement un email de rappel au validateur
 *
 * @param string $token_id ID du token
 * @return array ['success' => bool, 'message' => string]
 */
function remind_one(string $token_id): array {
    $pdo = get_pdo();

    // Récupérer le token avec les infos de la soumission
    $stmt = $pdo->prepare("
        SELECT t.*, s.data, s.status as sub_status, f.label as form_label
        FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        JOIN forms f ON f.id = s.form_id
        WHERE t.id = ?
    ");
    $stmt->execute([$token_id]);
    $tok = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tok) {
        return ['success' => false, 'message' => 'Token introuvable.'];
    }
    if ($tok['done_at']) {
        return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
    }
    if ($tok['sub_status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
    }

    // Récupérer le label de l'étape
    $step_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
    $step_stmt->execute([$tok['step_id']]);
    $step = $step_stmt->fetch(PDO::FETCH_ASSOC);

    // Incrémenter le compteur de relances
    $new_count = (int)$tok['relance_count'] + 1;
    $relance_max = (int)get_setting('relance_max', '3');

    $pdo->prepare("UPDATE tokens SET relance_count = ?, relance_at = datetime('now') WHERE id = ?")
        ->execute([$new_count, $token_id]);

    // Construire l'email de rappel
    $submission = [
        'data' => $tok['data'],
        'form_label' => $tok['form_label'],
    ];
    $subject = '[Rappel] ' . $tok['form_label'] . ' — ' . ($step['label'] ?? 'Validation requise');
    if ($new_count > 1) {
        $subject = '[Rappel ' . $new_count . '/' . $relance_max . '] ' . $tok['form_label'] . ' — ' . ($step['label'] ?? 'Validation requise');
    }

    $mail_body = build_mail_html($submission, $step['label'] ?? 'Validation', $tok['token']);
    // Ajouter un message de rappel en haut du corps
    $rappel_notice = '<div style="background:#fff3e0;border:1px solid #b45309;border-radius:4px;padding:12px;margin-bottom:16px;">
        <strong>⏰ Rappel :</strong> Cette demande est toujours en attente de votre validation.
        <br>Ceci est le rappel n°' . $new_count . ' sur un maximum de ' . $relance_max . '.
    </div>';
    $mail_body = str_replace('<h2 style="color:#003189;">', $rappel_notice . '<h2 style="color:#003189;">', $mail_body);

    $mail_sent = send_mail($tok['email'], $subject, $mail_body);

    app_log('manual_remind', 'token:' . $token_id, 'Rappel manuel envoyé à ' . $tok['email'] . ' (relance ' . $new_count . '/' . $relance_max . ')');

    if ($mail_sent) {
        return ['success' => true, 'message' => 'Rappel envoyé à ' . $tok['email'] . ' (relance ' . $new_count . '/' . $relance_max . ')'];
    } else {
        return ['success' => false, 'message' => 'Erreur lors de l\'envoi de l\'email à ' . $tok['email'] . '. Vérifiez la configuration SMTP.'];
    }
}

// ── FILE ATTACHMENTS ─────────────────────────────────────────

/**
 * Types MIME autorises pour les pieces jointes
 * Securise : pas d'executables, pas de scripts
 */
function get_allowed_mime_types(): array {
    return [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',
        'application/zip',
    ];
}

/**
 * Extensions autorisees (verification supplementaire)
 */
function get_allowed_extensions(): array {
    return ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];
}

/**
 * Taille maximale des fichiers en octets (10 Mo)
 */
function get_max_file_size(): int {
    return 10 * 1024 * 1024;
}

/**
 * Gère l'upload d'un fichier pour une soumission
 *
 * @param array $file Le tableau $_FILES['field_name']
 * @param string $submission_id ID de la soumission
 * @param string $field_name Nom du champ
 * @return array ['success' => bool, 'message' => string, 'attachment_id' => string|null]
 */
function handle_file_upload(array $file, string $submission_id, string $field_name): array {
    // Vérifier les erreurs d'upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
            UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille maximale autorisée par le formulaire.',
            UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement téléchargé.',
            UPLOAD_ERR_NO_FILE    => 'Aucun fichier n\'a été téléchargé.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Erreur d\'écriture sur le serveur.',
        ];
        return ['success' => false, 'message' => $errors[$file['error']] ?? 'Erreur inconnue lors de l\'upload.', 'attachment_id' => null];
    }

    // Vérifier la taille
    if ($file['size'] > get_max_file_size()) {
        return ['success' => false, 'message' => 'Le fichier dépasse la taille maximale autorisée (10 Mo).', 'attachment_id' => null];
    }

    // Vérifier l'extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, get_allowed_extensions())) {
        return ['success' => false, 'message' => 'Type de fichier non autorisé. Extensions acceptées : ' . implode(', ', get_allowed_extensions()) . '.', 'attachment_id' => null];
    }

    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime_type, get_allowed_mime_types())) {
        return ['success' => false, 'message' => 'Type MIME non autorisé : ' . $mime_type . '.', 'attachment_id' => null];
    }

    // Lire le contenu du fichier pour stockage BLOB
    $file_content = file_get_contents($file['tmp_name']);
    if ($file_content === false) {
        return ['success' => false, 'message' => 'Erreur lors de la lecture du fichier.', 'attachment_id' => null];
    }

    // Enregistrer dans la base de données avec le contenu BLOB
    $pdo = get_pdo();
    $attachment_id = generate_uuid();
    $pdo->prepare("INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, '', ?, ?, ?, datetime('now'))")
        ->execute([$attachment_id, $submission_id, $field_name, $file['name'], $mime_type, $file['size'], $file_content]);

    app_log('file_upload', 'submission:' . $submission_id, 'Fichier uploadé : ' . $file['name'] . ' (' . $mime_type . ', ' . $file['size'] . ' octets)');

    return ['success' => true, 'message' => 'Fichier ' . $file['name'] . ' enregistré.', 'attachment_id' => $attachment_id];
}

/**
 * Récupère les pièces jointes d'une soumission
 *
 * @param string $submission_id ID de la soumission
 * @return array Liste des pièces jointes
 */
function get_attachments(string $submission_id): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM attachments WHERE submission_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Récupère une pièce jointe par son ID
 * Vérifie l'accès avant de retourner
 *
 * @param string $attachment_id ID de la pièce jointe
 * @return array|null Données de la pièce jointe ou null
 */
function get_attachment_by_id(string $attachment_id): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Formate la taille d'un fichier en unités lisibles
 */
function format_file_size(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' Mo';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' Ko';
    }
    return $bytes . ' octets';
}

/**
 * Retourne l'icône correspondant au type de fichier
 */
function get_file_icon(string $mime_type): string {
    if (strpos($mime_type, 'pdf') !== false) return '📄';
    if (strpos($mime_type, 'image') !== false) return '🖼';
    if (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) return '📝';
    if (strpos($mime_type, 'sheet') !== false || strpos($mime_type, 'excel') !== false) return '📊';
    if (strpos($mime_type, 'presentation') !== false || strpos($mime_type, 'powerpoint') !== false) return '📽';
    if (strpos($mime_type, 'zip') !== false) return '📦';
    if (strpos($mime_type, 'text') !== false) return '📃';
    return '📎';
}

// ── DELEGATION ───────────────────────────────────────────────

/**
 * Délègue un token de validation à un autre validateur
 * L'ancien token est marqué comme traité (délégué) et un nouveau token est créé
 *
 * @param string $token_id ID du token à déléguer
 * @param string $to_email Email du délégataire
 * @param string $reason Motif de la délégation
 * @return array ['success' => bool, 'message' => string]
 */
function delegate_token(string $token_id, string $to_email, string $reason = ''): array {
    $pdo = get_pdo();

    // Récupérer le token
    $stmt = $pdo->prepare("
        SELECT t.*, s.status as sub_status, s.data, f.label as form_label
        FROM tokens t
        JOIN submissions s ON s.id = t.submission_id
        JOIN forms f ON f.id = s.form_id
        WHERE t.id = ?
    ");
    $stmt->execute([$token_id]);
    $tok = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tok) {
        return ['success' => false, 'message' => 'Token introuvable.'];
    }
    if ($tok['done_at']) {
        return ['success' => false, 'message' => 'Ce token a déjà été traité.'];
    }
    if ($tok['sub_status'] !== 'en_cours') {
        return ['success' => false, 'message' => 'La soumission n\'est plus en cours.'];
    }

    // Valider l'email du délégataire
    $to_email = strtolower(trim($to_email));
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Adresse email invalide.'];
    }
    if ($to_email === $tok['email']) {
        return ['success' => false, 'message' => 'Vous ne pouvez pas déléguer à vous-même.'];
    }

    // Vérifier qu'un token n'existe pas déjà pour cet email sur cette étape
    $dup_check = $pdo->prepare("SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL");
    $dup_check->execute([$tok['submission_id'], $tok['step_id'], $to_email]);
    if ($dup_check->fetch()) {
        return ['success' => false, 'message' => 'Un token de validation est déjà actif pour ' . $to_email . ' sur cette étape.'];
    }

    // Marquer l'ancien token comme traité (délégué)
    $pdo->prepare("UPDATE tokens SET done_at = datetime('now') WHERE id = ?")
        ->execute([$token_id]);

    // Créer le nouveau token pour le délégataire
    $new_token = generate_token();
    $expire_days = (int)get_setting('token_expire_days', '30');
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
    $now = date('Y-m-d H:i:s');

    $new_token_row_id = generate_uuid();
    $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$new_token_row_id, $tok['submission_id'], $tok['step_id'], $to_email, $new_token, $now, $expires_at]);

    $new_token_id = $new_token_row_id;

    // Enregistrer la délégation
    $delegation_id = generate_uuid();
    $pdo->prepare("INSERT INTO delegations (id, token_id, from_email, to_email, reason, delegated_at, new_token_id) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)")
        ->execute([$delegation_id, $token_id, $tok['email'], $to_email, $reason, $new_token_id]);

    // Envoyer l'email au délégataire
    $step_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ?");
    $step_stmt->execute([$tok['step_id']]);
    $step = $step_stmt->fetch(PDO::FETCH_ASSOC);

    $submission = [
        'data' => $tok['data'],
        'form_label' => $tok['form_label'],
    ];

    $subject = '[Délégation] ' . $tok['form_label'] . ' — ' . ($step['label'] ?? 'Validation requise');
    $mail_body = build_mail_html($submission, $step['label'] ?? 'Validation', $new_token);

    // Ajouter un bloc de délégation en haut de l'email
    $delegation_notice = '<div style="background:#e8eaf6;border:1px solid #003189;border-radius:4px;padding:12px;margin-bottom:16px;">
        <strong>🔄 Délégation :</strong> Cette validation vous a été déléguée par <strong>' . h($tok['email']) . '</strong>.
        ' . (!empty($reason) ? '<br><em>Motif : ' . h($reason) . '</em>' : '') . '
    </div>';
    $mail_body = str_replace('<h2 style="color:#003189;">', $delegation_notice . '<h2 style="color:#003189;">', $mail_body);

    send_mail($to_email, $subject, $mail_body);

    // Notifier le délégateur que sa délégation a été prise en compte
    $confirm_subject = 'Délégation confirmée — ' . $tok['form_label'];
    $confirm_body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#003189;">🔄 Délégation confirmée</h2>
  <p>Votre validation pour <strong>' . h($tok['form_label']) . '</strong> (étape ' . h($step['label'] ?? '') . ') a été déléguée à <strong>' . h($to_email) . '</strong>.</p>
  <p>Vous n\'avez plus besoin d\'effectuer cette validation.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">' . h(get_app_name()) . ' — Ne pas répondre à cet email</p>
</body></html>';
    send_mail($tok['email'], $confirm_subject, $confirm_body);

    app_log('token_delegate', 'token:' . $token_id, 'Token délégué de ' . $tok['email'] . ' à ' . $to_email . ($reason ? ' — Motif : ' . $reason : ''));

    return ['success' => true, 'message' => 'Validation déléguée à ' . $to_email . '. Un email lui a été envoyé.'];
}

/**
 * Récupère l'historique des délégations pour une soumission
 */
function get_delegations(string $submission_id): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("
        SELECT d.*, t.step_id, st.label as step_label
        FROM delegations d
        JOIN tokens t ON t.id = d.token_id
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id = ?
        ORDER BY d.delegated_at DESC
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── RGPD COMPLIANCE ──────────────────────────────────────────

/**
 * Exporte toutes les données d'un agent au format JSON (droit d'accès RGPD)
 */
function rgpd_export_user_data(string $email): array {
    $pdo = get_pdo();
    $data = ['email' => $email, 'export_date' => date('c'), 'submissions' => [], 'validations' => []];
    
    // Soumissions de l'agent
    $stmt = $pdo->prepare("SELECT s.*, f.label as form_label FROM submissions s JOIN forms f ON f.id = s.form_id WHERE s.submitted_by = ? ORDER BY s.submitted_at DESC");
    $stmt->execute([$email]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data['submissions'][] = [
            'id' => $row['id'],
            'form' => $row['form_label'],
            'status' => $row['status'],
            'submitted_at' => $row['submitted_at'],
            'closed_at' => $row['closed_at'],
            'data' => json_decode($row['data'], true),
        ];
    }
    
    // Validations effectuées par cet agent
    $stmt2 = $pdo->prepare("SELECT t.*, st.label as step_label, f.label as form_label FROM tokens t JOIN steps st ON st.id = t.step_id JOIN submissions s ON s.id = t.submission_id JOIN forms f ON f.id = s.form_id WHERE t.email = ? AND t.done_at IS NOT NULL ORDER BY t.done_at DESC");
    $stmt2->execute([$email]);
    $data['validations'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

/**
 * Supprime les données d'un agent (droit à l'effacement RGPD)
 * Anonymise les soumissions et supprime les pièces jointes
 */
function rgpd_delete_user_data(string $email): bool {
    $pdo = get_pdo();
    
    try {
        // Anonymiser les soumissions de l'agent
        $stmt = $pdo->prepare("SELECT id, data FROM submissions WHERE submitted_by = ?");
        $stmt->execute([$email]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = json_decode($row['data'], true) ?: [];
            // Anonymiser les champs personnels
            foreach (['prenom', 'nom', 'email', 'telephone', 'mobile', 'adresse'] as $field) {
                if (isset($data[$field])) $data[$field] = '[supprimé]';
            }
            $pdo->prepare("UPDATE submissions SET submitted_by = ?, data = ? WHERE id = ?")
                ->execute(['[supprimé]', json_encode($data, JSON_UNESCAPED_UNICODE), $row['id']]);
            // Supprimer les pièces jointes (BLOB)
            $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$row['id']]);
        }
        
        // Anonymiser les tokens de l'agent
        $pdo->prepare("UPDATE tokens SET email = '[supprimé]' WHERE email = ?")->execute([$email]);
        
        // Anonymiser les délégations
        $pdo->prepare("UPDATE delegations SET from_email = '[supprimé]' WHERE from_email = ?")->execute([$email]);
        $pdo->prepare("UPDATE delegations SET to_email = '[supprimé]' WHERE to_email = ?")->execute([$email]);
        
        // Supprimer les demandes admin
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$email]);
        
        // Supprimer l'accès admin
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$email]);
        
        app_log('rgpd_delete', 'user:' . $email, 'Données utilisateur supprimées (RGPD)', $email);
        return true;
    } catch (Exception $e) {
        error_log('RGPD delete error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Purge automatique des données anciennes (RGPD - conservation limitée)
 * Supprime les soumissions clôturées de plus de X mois
 */
function rgpd_auto_purge(int $months = 24): int {
    $pdo = get_pdo();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
    
    // Supprimer les pièces jointes des anciennes soumissions
    $stmt = $pdo->prepare("SELECT id FROM submissions WHERE status != 'en_cours' AND closed_at < ?");
    $stmt->execute([$cutoff]);
    $old_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $count = 0;
    foreach ($old_ids as $sid) {
        $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = ?)")->execute([$sid]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM alert_log WHERE submission_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sid]);
        $count++;
    }
    
    if ($count > 0) {
        app_log('rgpd_purge', '', "Purge RGPD : {$count} soumissions de plus de {$months} mois supprimées");
    }
    
    return $count;
}

// ── SECURITY HARDENING ──────────────────────────────────────

/**
 * Rate limiting par IP et par action
 * Retourne true si l'action est autorisée, false si le rate limit est atteint
 */
function rate_limit_check(string $action = 'default', int $max_attempts = 10, int $window_seconds = 60): bool {
    $pdo = get_pdo();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = $action . ':' . $ip;
    $now = time();
    $window_start = date('Y-m-d H:i:s', $now - $window_seconds);
    
    // Create rate_limits table if not exists
    static $table_created = false;
    if (!$table_created) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                id TEXT PRIMARY KEY NOT NULL,
                action_key TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (PDOException $e) {}
        $table_created = true;
    }
    
    // Count recent attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action_key = ? AND ip = ? AND attempted_at > ?");
    $stmt->execute([$action, $ip, $window_start]);
    $count = (int)$stmt->fetchColumn();
    
    if ($count >= $max_attempts) {
        app_log('rate_limit', 'action:' . $action, "Rate limit atteint pour IP {$ip} sur action {$action}");
        return false;
    }
    
    // Record this attempt
    $pdo->prepare("INSERT INTO rate_limits (id, action_key, ip, attempted_at) VALUES (?, ?, ?, datetime('now'))")
        ->execute([generate_uuid(), $action, $ip]);
    
    // Clean up old entries (keep last hour)
    $pdo->exec("DELETE FROM rate_limits WHERE attempted_at < datetime('now', '-1 hour')");
    
    return true;
}

/**
 * Sanitize input to prevent XSS and injection
 */
function sanitize_input(string $input): string {
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Validate and sanitize email
 */
function validate_email(string $email): string {
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

// ── FULL-TEXT SEARCH ────────────────────────────────────────

/**
 * Recherche plein texte dans les soumissions
 * Cherche dans : submitted_by, data JSON, form_label
 */
function search_submissions(string $query, array $filters = []): array {
    $pdo = get_pdo();
    $query = trim($query);
    if (empty($query)) return [];
    
    $where = ['1=1'];
    $params = [];
    
    // Full-text search across multiple fields
    $where[] = "(s.submitted_by LIKE ? OR s.data LIKE ? OR f.label LIKE ?)";
    $search_term = '%' . $query . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    
    // Apply filters
    if (!empty($filters['status'])) {
        $where[] = 's.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['form_id'])) {
        $where[] = 's.form_id = ?';
        $params[] = $filters['form_id'];
    }
    
    $where_sql = implode(' AND ', $where);
    
    $stmt = $pdo->prepare("
        SELECT s.*, f.label as form_label, f.slug as form_slug, f.deadline_field
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE $where_sql
        ORDER BY s.submitted_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── STATISTICS ──────────────────────────────────────────────

/**
 * Statistiques par période
 */
function get_stats_by_period(string $period = 'month', int $limit = 12): array {
    $pdo = get_pdo();
    
    switch ($period) {
        case 'week':
            $format = '%Y-W%W';
            $interval = '-12 weeks';
            break;
        case 'year':
            $format = '%Y';
            $interval = '-5 years';
            break;
        default: // month
            $format = '%Y-%m';
            $interval = '-12 months';
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            strftime(?, s.submitted_at) as period,
            COUNT(*) as total,
            SUM(CASE WHEN s.status = 'valide' THEN 1 ELSE 0 END) as valide,
            SUM(CASE WHEN s.status = 'refuse' THEN 1 ELSE 0 END) as refuse,
            SUM(CASE WHEN s.status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
            AVG(CASE WHEN s.status = 'valide' AND s.closed_at IS NOT NULL 
                THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL) 
                ELSE NULL END) as avg_processing_seconds
        FROM submissions s
        WHERE s.submitted_at >= datetime('now', ?)
        GROUP BY strftime(?, s.submitted_at)
        ORDER BY period DESC
        LIMIT ?
    ");
    $stmt->execute([$format, $interval, $format, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Statistiques globales pour le dashboard
 */
function get_global_stats(): array {
    $pdo = get_pdo();
    
    $stats = [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn(),
        'en_cours' => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'en_cours'")->fetchColumn(),
        'valide' => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'valide'")->fetchColumn(),
        'refuse' => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'refuse'")->fetchColumn(),
        'avg_days' => 0,
        'today' => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE DATE(submitted_at) = DATE('now')")->fetchColumn(),
        'this_week' => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE submitted_at >= datetime('now', '-7 days')")->fetchColumn(),
        'this_month' => (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE submitted_at >= datetime('now', '-30 days')")->fetchColumn(),
        'tokens_pending' => (int)$pdo->query("SELECT COUNT(*) FROM tokens WHERE done_at IS NULL")->fetchColumn(),
        'attachments_count' => (int)$pdo->query("SELECT COUNT(*) FROM attachments")->fetchColumn(),
        'attachments_size' => (int)$pdo->query("SELECT COALESCE(SUM(file_size), 0) FROM attachments")->fetchColumn(),
    ];
    
    // Average processing time
    $avg_stmt = $pdo->query("
        SELECT AVG(CAST(strftime('%s', closed_at) AS REAL) - CAST(strftime('%s', submitted_at) AS REAL))
        FROM submissions WHERE status = 'valide' AND closed_at IS NOT NULL
    ");
    $stats['avg_days'] = round((float)($avg_stmt->fetchColumn() ?: 0) / 86400, 1);
    
    $stats['taux_validation'] = $stats['total'] > 0 ? round(($stats['valide'] / $stats['total']) * 100, 1) : 0;
    
    return $stats;
}

// ── WEBHOOK NOTIFICATIONS ───────────────────────────────────

/**
 * Envoie une notification webhook si configuré
 */
function send_webhook(string $event, array $data): void {
    $webhook_url = get_setting('webhook_url', '');
    $webhook_events = get_setting('webhook_events', '');
    
    if (empty($webhook_url)) return;
    
    // Check if this event is in the configured events list
    $allowed_events = array_filter(array_map('trim', explode(',', $webhook_events)));
    if (!empty($allowed_events) && !in_array($event, $allowed_events) && !in_array('all', $allowed_events)) {
        return;
    }
    
    $payload = json_encode([
        'event' => $event,
        'timestamp' => date('c'),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    
    // Send async webhook via curl (non-blocking)
    if (function_exists('curl_init')) {
        $ch = curl_init($webhook_url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Event: ' . $event],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
