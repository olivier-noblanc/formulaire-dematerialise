<?php
require_once __DIR__ . '/config.php';
session_start();
// Tentative d'inclusion de vendor/autoload.php, mais ignorée si non présente
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

// ── UTILITAIRES ──────────────────────────────────────────────
function get_auth_user(): string {
    $auth_user = $_SERVER['AUTH_USER'] ?? '';
    if (empty($auth_user)) {
        http_response_code(401);
        die('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authentification requise — DREETS</title>
<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Marianne",Arial,sans-serif;background:#f5f5fe;color:#1e1e1e;display:flex;min-height:100vh;align-items:center;justify-content:center}
.error-box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:2.5rem;max-width:520px;width:90%;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.error-icon{font-size:3rem;margin-bottom:1rem}
h1{font-size:1.3rem;color:#003189;margin-bottom:.75rem}
p{color:#555;font-size:.95rem;line-height:1.6;margin-bottom:.75rem}
.hint{font-size:.85rem;color:#888;background:#f5f5f5;border-radius:4px;padding:.75rem;margin-top:1rem;text-align:left}
.hint strong{color:#333}
</style></head><body>
<div class="error-box">
<div class="error-icon">🔒</div>
<h1>Authentification requise</h1>
<p>Cette application nécessite une authentification Windows (IIS) pour fonctionner.</p>
<p>La variable d\'environnement <strong>AUTH_USER</strong> n\'est pas disponible, ce qui indique que l\'authentification Windows n\'est pas configurée ou n\'a pas pu être établie.</p>
<div class="hint">
<strong>Que faire ?</strong><br>
• Vérifiez que vous accédez à l\'application via le réseau interne DREETS.<br>
• Vérifiez que l\'authentification Windows est activée dans IIS (Anonymous Authentication doit être désactivé).<br>
• Contactez votre administrateur réseau si le problème persiste.
</div>
</div></body></html>');
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
    return $auth_user === ADMIN_EMAIL;
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
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ── PDO ──────────────────────────────────────────────────────
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Appel à la migration de base de données au premier accès
        db_migrate($pdo);
    }
    return $pdo;
}

/**
 * Migration automatique de la base de données
 * Crée les tables si elles n'existent pas et effectue les mises à jour nécessaires
 */
function db_migrate(PDO $pdo): void {
    // Activer le mode WAL pour améliorer la concurrence
    $pdo->exec('PRAGMA journal_mode=WAL');
    
    // Création des tables avec CREATE TABLE IF NOT EXISTS
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            label TEXT NOT NULL,
            description TEXT,
            actif INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS steps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            ordre INTEGER NOT NULL,
            actif INTEGER DEFAULT 1,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS step_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            step_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            FOREIGN KEY (step_id) REFERENCES steps(id) ON DELETE CASCADE
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id INTEGER NOT NULL,
            step_id INTEGER NOT NULL,
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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT UNIQUE NOT NULL,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL DEFAULT 'text',
            field_name TEXT NOT NULL,
            options TEXT,
            required INTEGER DEFAULT 0,
            ordre INTEGER DEFAULT 0,
            card_group TEXT DEFAULT 'Général',
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");

    // Table d'audit log — tracabilite de toutes les actions admin
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
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
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rule_id INTEGER NOT NULL,
            submission_id INTEGER NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            message TEXT,
            FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        )
    ");
    
    // Vérifier si la table admins est vide et insérer l'administrateur principal si nécessaire
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM admins");
    if ($count_stmt->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO admins (email, added_at) VALUES (?, ?)")
            ->execute([ADMIN_EMAIL, date('Y-m-d H:i:s')]);
    }

    // Seed formulaire outboarding si la table forms est vide ou ne contient que l'onboarding
    $ob_count = $pdo->query("SELECT COUNT(*) FROM forms WHERE slug = 'outboarding'")->fetchColumn();
    if ($ob_count == 0) {
        $pdo->prepare("INSERT INTO forms (slug, label, description, actif, created_at) VALUES (?, ?, ?, 1, datetime('now'))")
            ->execute(['outboarding', 'Outboarding agent', 'Formulaire de départ d\'un agent — restitution du matériel, cloture des accès et formalités de fin de contrat']);
        $outboarding_id = (int)$pdo->lastInsertId();

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
        $stmt_ob = $pdo->prepare("INSERT INTO form_fields (card_group, label, field_type, field_name, options, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($outboarding_fields as $row) {
            $stmt_ob->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $outboarding_id]);
        }

        // Etapes par defaut pour l'outboarding
        $steps_data = [
            ['Responsable direct', 1],
            ['Service informatique', 2],
            ['Ressources humaines', 3],
            ['Logistique', 4],
        ];
        $stmt_step = $pdo->prepare("INSERT INTO steps (form_id, label, ordre, actif) VALUES (?, ?, ?, 1)");
        foreach ($steps_data as $sd) {
            $stmt_step->execute([$outboarding_id, $sd[0], $sd[1]]);
        }
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
            ['smtp_from_name', 'Workflow DREETS'],
            ['delai_relance_h', '48'],
            ['token_expire_days', '30'],
            ['relance_max', '3'],
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
    
    // Seed default form_fields for the "onboarding" form if empty
    $ff_count = $pdo->query("SELECT COUNT(*) FROM form_fields")->fetchColumn();
    if ($ff_count == 0) {
        // Find the onboarding form id
        $ob = $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding' LIMIT 1")->fetchColumn();
        if ($ob) {
            $fid = (int)$ob;
            $defaults = [
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
            $stmt = $pdo->prepare("INSERT INTO form_fields (card_group, label, field_type, field_name, options, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($defaults as $row) {
                $stmt->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $fid]);
            }
        }
    }

    // Ajout de colonnes futures avec gestion d'erreur si déjà présentes
    try {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN closed_at DATETIME");
    } catch (PDOException $e) {
        // Ignorer si la colonne existe déjà
    }
    
    try {
        $pdo->exec("ALTER TABLE tokens ADD COLUMN relance_at DATETIME");
    } catch (PDOException $e) {
        // Ignorer si la colonne existe déjà
    }
    
    // Ajout de la colonne status à submissions si elle n'existe pas
    try {
        $pdo->exec("ALTER TABLE submissions ADD COLUMN status TEXT DEFAULT 'en_cours'");
    } catch (PDOException $e) {
        // Ignorer si la colonne existe déjà
    }
    
    // Ajout de la colonne expires_at à tokens si elle n'existe pas
    try {
        $pdo->exec("ALTER TABLE tokens ADD COLUMN expires_at DATETIME");
    } catch (PDOException $e) {
        // Ignorer si la colonne existe déjà
    }
    
    // Ajout de la colonne relance_count à tokens si elle n'existe pas
    try {
        $pdo->exec("ALTER TABLE tokens ADD COLUMN relance_count INTEGER DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorer si la colonne existe déjà
    }
    
    // Ajout de la colonne deadline_field à forms si elle n'existe pas
    try {
        $pdo->exec("ALTER TABLE forms ADD COLUMN deadline_field TEXT DEFAULT ''");
    } catch (PDOException $e) {
        // Ignorer si la colonne existe déjà
    }

    // Seed des regles d'alerte par defaut si la table est vide
    try {
        $alert_count = $pdo->query("SELECT COUNT(*) FROM alert_rules")->fetchColumn();
        if ($alert_count == 0) {
            // Onboarding : alerter 5 jours et 2 jours avant la prise de poste
            $onb = $pdo->query("SELECT id FROM forms WHERE slug = 'onboarding' LIMIT 1")->fetchColumn();
            if ($onb) {
                $stmt_ar = $pdo->prepare("INSERT INTO alert_rules (form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt_ar->execute([$onb, 5, 'steps_incomplete', 'admin', 'Alerte J-5 : étapes non complétées']);
                $stmt_ar->execute([$onb, 2, 'steps_incomplete', 'admin', 'Alerte J-2 : étapes non complétées']);
                // Mettre à jour le deadline_field pour l'onboarding
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")->execute(['date_prise_poste', $onb]);
            }
            // Outboarding : alerter 5 jours et 2 jours avant le départ
            $ob = $pdo->query("SELECT id FROM forms WHERE slug = 'outboarding' LIMIT 1")->fetchColumn();
            if ($ob) {
                $stmt_ar = $pdo->prepare("INSERT INTO alert_rules (form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt_ar->execute([$ob, 5, 'steps_incomplete', 'admin', 'Alerte J-5 : étapes non complétées']);
                $stmt_ar->execute([$ob, 2, 'steps_incomplete', 'admin', 'Alerte J-2 : étapes non complétées']);
                // Mettre à jour le deadline_field pour l'outboarding
                $pdo->prepare("UPDATE forms SET deadline_field = ? WHERE id = ?")->execute(['date_depart', $ob]);
            }
        }
    } catch (PDOException $e) {
        // Ignorer si déjà fait
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
    // Minuscules
    $name = mb_strtolower($label, 'UTF-8');
    // Supprimer les accents
    $name = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
    if ($name === false) {
        // Fallback manuel si intl pas dispo
        $name = str_replace(
            ['à','â','ä','é','è','ê','ë','ï','î','ô','ö','ù','û','ü','ç','œ','æ','ÿ'],
            ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','c','oe','ae','y'],
            $name
        );
    }
    // Remplacer tout ce qui n'est pas alphanumérique par un underscore
    $name = preg_replace('/[^a-z0-9]+/', '_', $name);
    // Nettoyer les underscores en double et en bordure
    $name = trim($name, '_');
    $name = preg_replace('/_+/', '_', $name);
    return $name ?: 'champ';
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

// ── FOOTER ────────────────────────────────────────────────────
function render_footer(): string {
    return '<footer style="text-align:center;padding:1.5rem 1rem;font-size:.78rem;color:#888;background:#f5f5fe;border-top:1px solid #eee;margin-top:2rem;">
  <a href="changelog.php" style="color:#003189;text-decoration:none;font-weight:bold;" title="Voir le journal des modifications">v' . h(APP_VERSION) . '</a>
  — Formulaire Dématérialisé DREETS
</footer>';
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

// ── MAIL ─────────────────────────────────────────────────────
function send_mail(string $to, string $subject, string $body): bool {
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
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
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
        if (empty($v) || $v === '0') continue;
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
function advance_workflow(int $submission_id): void {
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
                    $pdo->prepare("INSERT INTO tokens (submission_id, step_id, email, token, sent_at, expires_at) VALUES (?,?,?,?,?,?)")
                        ->execute([$submission_id, $step['id'], $email, $token, $now, $expires_at]);
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
        $subject = 'Demande validée — ' . ($submission['form_label'] ?? 'Workflow DREETS');
        $body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#1a6b3c;">✓ Demande validée</h2>
  <p>Votre demande <strong>' . h($submission['form_label'] ?? '') . '</strong> a été <strong>validée</strong> par l\'ensemble des validateurs.</p>
  <p>Le processus de workflow est désormais terminé.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">Workflow DREETS — ' . h(get_setting('smtp_from', SMTP_FROM)) . '</p>
</body></html>';
        send_mail($agent_email, $subject, $body);
    }

    app_log('workflow_complete', 'submission:' . $submission_id, 'Formulaire ' . ($submission['form_label'] ?? '') . ' validé', $agent_email);
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
    if (!empty($t['expires_at']) && strtotime($t['expires_at']) < time()) {
        return ['status' => 'expired', 'data' => $t];
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
            $refuse_subject = 'Demande refusée — ' . ($t['form_label'] ?? 'Workflow DREETS');
            $refuse_body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#c0392b;">Demande refusée</h2>
  <p>Votre demande <strong>' . h($t['form_label'] ?? '') . '</strong> a été refusée à l\'étape <strong>' . h($t['step_label']) . '</strong>.</p>
  ' . (!empty($comment) ? '<p><strong>Motif :</strong> ' . h($comment) . '</p>' : '') . '
  <p style="font-size:12px;color:#999;margin-top:24px;">Workflow DREETS — ' . h(get_setting('smtp_from', SMTP_FROM)) . '</p>
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

    $t['done_at'] = date('Y-m-d H:i:s');
    return ['status' => 'ok', 'data' => $t];
}

// ── ACTIVE SUBMISSIONS CHECK ───────────────────────────────────

/**
 * Vérifie si un formulaire a des soumissions actives (en_cours)
 */
function has_active_submissions(int $form_id): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE form_id = ? AND status = 'en_cours'");
    $stmt->execute([$form_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Vérifie si une étape a des soumissions actives (tokens en cours sur cette étape)
 */
function has_active_step_submissions(int $step_id): int {
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
        $stmt = $pdo->prepare("INSERT INTO admin_requests (email, requested_at, status, token) VALUES (?, ?, 'pending', ?)");
        $stmt->execute([$email, date('Y-m-d H:i:s'), $token]);
        
        app_log('admin_request', 'admin:' . $email, 'Demande d\'accès admin', $email);

        // Envoie un email à l'admin principal pour approbation
        $approve_url = BASE_URL . '/admin_access.php?action=approve&token=' . $token;
        $reject_url = BASE_URL . '/admin_access.php?action=reject&token=' . $token;
        $subject = 'Demande d\'accès admin - Workflow DREETS';
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
        
        send_mail(ADMIN_EMAIL, $subject, $body);
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
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO admins (email, added_at) VALUES (?, ?)");
        $stmt->execute([$email, date('Y-m-d H:i:s')]);
        
        // Envoie un email de confirmation
        $subject = 'Accès admin approuvé - Workflow DREETS';
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
        $subject = 'Demande d\'accès admin refusée - Workflow DREETS';
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
    if ($email === ADMIN_EMAIL) {
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
        $pdo->prepare("INSERT INTO audit_log (action, target, detail, actor, ip, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
            ->execute([$action, $target, $detail, $actor, $ip]);
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
function export_csv(PDO $pdo, array $options = []): void {
    $where = ['1=1'];
    $params = [];
    if (!empty($options['form_id'])) {
        $where[] = 's.form_id = ?';
        $params[] = (int)$options['form_id'];
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
    fputcsv($out, $headers, ';');

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
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}

// ── TOKEN REGENERATION ───────────────────────────────────────

/**
 * Régénère un token expiré pour un validateur (admin uniquement)
 * Invalide l'ancien token et crée un nouveau avec une nouvelle date d'expiration
 *
 * @param int $old_token_id ID de l'ancien token
 * @return array ['success' => bool, 'message' => string]
 */
function regenerate_token(int $old_token_id): array {
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

    $pdo->prepare("INSERT INTO tokens (submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$old['submission_id'], $old['step_id'], $old['email'], $new_token, $now, $expires_at]);

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
 * @param int $submission_id ID de la soumission
 * @param string $cancelled_by Email de l'utilisateur qui annule
 * @return array ['success' => bool, 'message' => string]
 */
function cancel_submission(int $submission_id, string $cancelled_by = ''): array {
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
        $subject = 'Demande annulée — ' . ($submission['form_label'] ?? 'Workflow DREETS');
        $body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;color:#222;">
  <h2 style="color:#b45309;">Demande annulée</h2>
  <p>Votre demande <strong>' . h($submission['form_label'] ?? '') . '</strong> a été annulée.</p>
  <p style="font-size:12px;color:#999;margin-top:24px;">Workflow DREETS</p>
</body></html>';
        send_mail($agent_email, $subject, $body);
    }

    app_log('submission_cancel', 'submission:' . $submission_id, 'Soumission annulée', $cancelled_by);

    return ['success' => true, 'message' => 'Soumission annulée avec succès.'];
}
