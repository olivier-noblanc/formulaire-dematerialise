<?php
declare(strict_types=1);

/**
 * install.php — Assistant d'installation de premier lancement
 *
 * Ce fichier est entièrement autonome : il ne dépend ni de config.php
 * ni de helpers.php ni de style.php, car ces fichiers nécessitent
 * une configuration déjà en place. Il intègre son propre CSS inline
 * et sa propre gestion CSRF.
 *
 * Le rendu HTML des 3 étapes du wizard est extrait vers
 * lib/render_install.php pour garder ce fichier sous 600 lignes
 * (refactor « all-under-600 »).
 *
 * Étapes :
 *   1. Vérification des prérequis (PHP ≥ 8.0, SQLite3, PDO SQLite, intl, écriture, PHPMailer)
 *   2. Formulaire de configuration (BASE_URL, SMTP, admin, délai)
 *   3. Confirmation et écriture de config.php
 */


// ── Sécurité de base ──────────────────────────────────────────
// Sécurité (S-17) : configurer les flags de cookie de session avant session_start()
$is_secure_install = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'httponly'  => true,
    'samesite'  => 'Strict',
    'secure'    => $is_secure_install,
]);
session_start();
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// ── Si config.php existe déjà, rediriger vers index.php ──────
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit;
}
// Sécurité : même si config.php est supprimé, ne pas réinstaller si la DB existe
if (file_exists(__DIR__ . '/db/workflow.db') && filesize(__DIR__ . '/db/workflow.db') > 0) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>Installation déjà effectuée</h1><p>La base de données existe déjà. Supprimez install.php pour des raisons de sécurité.</p></body></html>';
    exit;
}

// ── Valeurs par défaut pour l'installation (A-14 / W4-3) ─────
// install.php est autonome (pas de helpers.php ni de DB disponible) :
// ces constantes locales centralisent les defaults affichés dans le
// formulaire d'installation et écrits dans le config.php généré.
// Après installation, toutes ces valeurs sont configurables via
// admin_settings.php (table settings).
const INST_DEFAULT_SMTP_HOST      = 'smtp.social.gouv.fr';
const INST_DEFAULT_SMTP_FROM      = 'workflow@exemple.invalid';
const INST_DEFAULT_SMTP_FROM_NAME = 'CircuitDémat';
const INST_DEFAULT_APP_NAME       = 'CircuitDémat';
const INST_DEFAULT_EMAIL_DOMAIN   = 'exemple.invalid';
const INST_DEFAULT_DELAI_RELANCE  = 48;

// ── Fonctions utilitaires internes (standalone) ──────────────

/**
 * Échappement HTML
 */
function inst_h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

/**
 * Génération de token CSRF
 */
function inst_generate_csrf(): string {
    if (empty($_SESSION['inst_csrf_token'])) {
        $_SESSION['inst_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['inst_csrf_token'];
}

/**
 * Champ caché CSRF
 */
function inst_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . inst_h(inst_generate_csrf()) . '">';
}

/**
 * Vérification CSRF
 */
function inst_verify_csrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['inst_csrf_token'] ?? '', $token);
}

/**
 * Vérification des prérequis
 * Retourne un tableau [clé => ['ok' => bool, 'label' => string, 'detail' => string]]
 *
 * @return array<string, mixed>
 */
function inst_check_prerequisites(): array {
    $checks = [];

    // PHP >= 8.0
    $php_version = PHP_VERSION;
    $checks['php_version'] = [
        'ok'     => version_compare($php_version, '8.0.0', '>='),
        'label'  => 'PHP version >= 8.0',
        'detail' => 'Version détectée : ' . $php_version,
    ];

    // Extension SQLite3
    $checks['sqlite3'] = [
        'ok'     => extension_loaded('sqlite3'),
        'label'  => 'Extension SQLite3',
        'detail' => extension_loaded('sqlite3') ? 'Chargée' : 'Non chargée',
    ];

    // Pilote PDO SQLite
    $pdo_drivers = PDO::getAvailableDrivers();
    $checks['pdo_sqlite'] = [
        'ok'     => in_array('sqlite', $pdo_drivers, true),
        'label'  => 'Pilote PDO SQLite',
        'detail' => in_array('sqlite', $pdo_drivers, true) ? 'Disponible' : 'Non disponible (pilotes : ' . implode(', ', $pdo_drivers) . ')',
    ];

    // Extension intl
    $checks['intl'] = [
        'ok'     => extension_loaded('intl'),
        'label'  => 'Extension intl (Transliterator)',
        'detail' => extension_loaded('intl') ? 'Chargée' : 'Non chargée — requise pour la translittération',
    ];

    // Droit d'écriture sur le répertoire courant (pour créer db/)
    $writable = is_writable(__DIR__);
    $checks['writable'] = [
        'ok'     => $writable,
        'label'  => 'Droit d\'écriture sur le répertoire',
        'detail' => $writable ? __DIR__ : 'Le répertoire ' . __DIR__ . ' n\'est pas accessible en écriture',
    ];

    // Répertoire PHPMailer
    $phpmailer_exists = is_dir(__DIR__ . '/PHPMailer');
    $checks['phpmailer'] = [
        'ok'     => $phpmailer_exists,
        'label'  => 'Répertoire PHPMailer',
        'detail' => $phpmailer_exists ? 'Présent' : 'Non trouvé — les fichiers PHPMailer/PHPMailer.php, SMTP.php et Exception.php sont requis',
    ];

    return $checks;
}

/**
 * Auto-détection de BASE_URL
 */
function inst_detect_base_url(): string {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/workflow');
    // Normaliser : enlever le slash final sauf si c'est la racine
    $script_dir = rtrim($script_dir, '/');
    if ($script_dir === '' || $script_dir === '\\') {
        $script_dir = '/workflow';
    }
    return $protocol . '://' . $hostname . $script_dir;
}

/**
 * Tentative d'envoi d'un email de test via PHPMailer
 * Retourne ['success' => bool, 'message' => string]
 *
 * @return array<string, mixed>
 */
function inst_test_smtp(string $host, int $port, string $from, string $from_name, string $to): array {
    // Vérifier que PHPMailer est disponible
    $pm_dir = __DIR__ . '/PHPMailer';
    if (!is_dir($pm_dir)) {
        return ['success' => false, 'message' => 'Le répertoire PHPMailer n\'existe pas. Impossible de tester l\'envoi.'];
    }

    $pm_files = [
        $pm_dir . '/Exception.php',
        $pm_dir . '/PHPMailer.php',
        $pm_dir . '/SMTP.php',
    ];
    foreach ($pm_files as $f) {
        if (!file_exists($f)) {
            return ['success' => false, 'message' => 'Fichier manquant : ' . basename($f) . '. Impossible de tester l\'envoi.'];
        }
    }

    require_once $pm_dir . '/Exception.php';
    require_once $pm_dir . '/PHPMailer.php';
    require_once $pm_dir . '/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host     = $host;
        $mail->Port     = $port;
        $mail->SMTPAuth = false;
        $mail->CharSet  = 'UTF-8';
        $mail->setFrom($from, $from_name);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Test SMTP — Installation ' . \App\Render\NavigationRenderer::getAppName();
        $mail->Body    = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test d\'envoi d\'email</h2>
  <p>Cet email a été envoyé depuis l\'assistant d\'installation de ' . \App\Render\NavigationRenderer::getAppName() . '.</p>
  <p>Date : ' . date('d/m/Y H:i:s') . '</p>
  <hr style="margin:1rem 0;border:none;border-top:1px solid #ddd;">
  <p style="font-size:.85rem;color:#595959;">Si vous recevez cet email, la configuration SMTP est correcte.</p>
</body></html>';
        $mail->send();
        return ['success' => true, 'message' => 'Email de test envoyé avec succès à ' . $to];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return ['success' => false, 'message' => 'Échec de l\'envoi : ' . $mail->ErrorInfo];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Erreur : ' . $e->getMessage()];
    }
}

/**
 * Écrit le fichier config.php
 * Retourne ['success' => bool, 'message' => string]
 *
 * @param array<string, mixed> $values
 * @return array<string, mixed>
 */
function inst_write_config(array $values): array {
    // Créer le répertoire db/ s'il n'existe pas
    $db_dir = __DIR__ . '/db';
    if (!is_dir($db_dir)) {
        if (!mkdir($db_dir, 0755, true)) {
            return ['success' => false, 'message' => 'Impossible de créer le répertoire ' . $db_dir];
        }
    }

    // Vérifier que db/ est accessible en écriture
    if (!is_writable($db_dir)) {
        return ['success' => false, 'message' => 'Le répertoire ' . $db_dir . ' n\'est pas accessible en écriture.'];
    }

    $config_content = "<?php\n"
        . "// Configuration générée par l'assistant d'installation — " . date('Y-m-d H:i:s') . "\n"
        . "// Sécurité (S-08) : validation HTTP_HOST contre injection d'en-tête\n"
        . "\$protocol = isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on' ? 'https' : 'http';\n"
        . "\$allowed_hosts = ['localhost', '127.0.0.1'];\n"
        . "\$requested_host = \$_SERVER['HTTP_HOST'] ?? 'localhost';\n"
        . "if (in_array(\$requested_host, \$allowed_hosts) || stripos(\$requested_host, '" . INST_DEFAULT_EMAIL_DOMAIN . "') !== false) {\n"
        . "    \$hostname = \$requested_host;\n"
        . "} else {\n"
        . "    \$hostname = 'localhost';\n"
        . "}\n"
        . "define('BASE_URL',       \$protocol . '://' . \$hostname . '" . addslashes($values['base_url_path']) . "');\n"
        . "define('DB_PATH',        __DIR__ . '/db/workflow.db');\n"
        . "date_default_timezone_set('Europe/Paris');\n"
        . "\n"
        . "// ── VALEURS PAR DÉFAUT DES SETTINGS ───────────────────────────\n"
        . "// La base de données (table settings) est la source primaire.\n"
        . "// Ces valeurs ne sont utilisées que si le setting n'existe pas en DB.\n"
        . "// Pour modifier un paramètre : Paramètres → admin_settings.php\n"
        . "define('SETTINGS_DEFAULTS', [\n"
        . "    'smtp_host'        => '" . addslashes($values['smtp_host']) . "',\n"
        . "    'smtp_port'        => '" . (int)$values['smtp_port'] . "',\n"
        . "    'smtp_from'        => '" . addslashes($values['smtp_from']) . "',\n"
        . "    'smtp_from_name'   => '" . addslashes($values['smtp_from_name']) . "',\n"
        . "    'delai_relance_h'  => '" . (int)$values['delai_relance_h'] . "',\n"
        . "    'admin_email'      => '" . addslashes($values['admin_email']) . "',\n"
        . "    'token_expire_days'=> '30',\n"
        . "    'relance_max'      => '3',\n"
        . "    'mail_dry_run'     => '1',\n"
        . "    'app_name'         => '" . INST_DEFAULT_APP_NAME . "',\n"
        . "    'retention_months' => '24',\n"
        . "    // A-14 : domaine email configurable\n"
        . "    'email_domain'     => '" . INST_DEFAULT_EMAIL_DOMAIN . "',\n"
        . "]);\n";

    $config_path = __DIR__ . '/config.php';
    $result = file_put_contents($config_path, $config_content, LOCK_EX);

    if ($result === false) {
        return ['success' => false, 'message' => 'Impossible d\'écrire le fichier ' . $config_path];
    }

    return ['success' => true, 'message' => 'Fichier config.php créé avec succès.'];
}

// ── Gestion des étapes ───────────────────────────────────────
$step = 1;
$messages = [];
$error_messages = [];

// Déterminer l'étape depuis GET ou POST
if (isset($_GET['step']) && is_numeric($_GET['step'])) {
    $step = max(1, min(3, (int)$_GET['step']));
}

// ── Traitement des actions POST ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!inst_verify_csrf()) {
        $error_messages[] = 'Token CSRF invalide. Veuillez réessayer.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Action : Aller à l'étape 2 ──
        if ($action === 'to_step2') {
            $prereqs = inst_check_prerequisites();
            $all_ok = true;
            foreach ($prereqs as $check) {
                if (!$check['ok']) {
                    $all_ok = false;
                    break;
                }
            }
            if ($all_ok) {
                $step = 2;
            } else {
                $error_messages[] = 'Tous les prérequis doivent être satisfaits avant de continuer.';
                $step = 1;
            }
        }

        // ── Action : Retour à l'étape 1 ──
        elseif ($action === 'back_step1') {
            $step = 1;
        }

        // ── Action : Test SMTP ──
        elseif ($action === 'test_smtp') {
            $smtp_host    = trim($_POST['smtp_host'] ?? INST_DEFAULT_SMTP_HOST);
            $smtp_port    = (int)($_POST['smtp_port'] ?? 25);
            $smtp_from    = trim($_POST['smtp_from'] ?? INST_DEFAULT_SMTP_FROM);
            $smtp_from_name = trim($_POST['smtp_from_name'] ?? INST_DEFAULT_SMTP_FROM_NAME);
            $admin_email  = trim($_POST['admin_email'] ?? '');

            if (empty($admin_email) || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                $error_messages[] = 'L\'email administrateur est requis et doit être valide pour tester l\'envoi.';
            } else {
                $smtp_result = inst_test_smtp($smtp_host, $smtp_port, $smtp_from, $smtp_from_name, $admin_email);
                if ($smtp_result['success']) {
                    $messages[] = $smtp_result['message'];
                } else {
                    $error_messages[] = $smtp_result['message'];
                }
            }
            $step = 2;
        }

        // ── Action : Générer la configuration (aller à étape 3) ──
        elseif ($action === 'generate_config') {
            $base_url     = trim($_POST['base_url'] ?? '');
            $smtp_host    = trim($_POST['smtp_host'] ?? '');
            $smtp_port    = trim($_POST['smtp_port'] ?? '25');
            $smtp_from    = trim($_POST['smtp_from'] ?? '');
            $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');
            $admin_email  = trim($_POST['admin_email'] ?? '');
            $delai_relance_h = trim($_POST['delai_relance_h'] ?? '48');

            // Validation
            $validation_errors = [];
            if (empty($base_url)) {
                $validation_errors[] = 'L\'URL de base est requise.';
            } elseif (!filter_var($base_url, FILTER_VALIDATE_URL)) {
                $validation_errors[] = 'L\'URL de base n\'est pas une URL valide.';
            }
            if (empty($smtp_host)) {
                $validation_errors[] = 'L\'hôte SMTP est requis.';
            }
            if (empty($smtp_port) || (int)$smtp_port < 1 || (int)$smtp_port > 65535) {
                $validation_errors[] = 'Le port SMTP doit être un nombre entre 1 et 65535.';
            }
            if (empty($smtp_from) || !filter_var($smtp_from, FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = 'L\'email expéditeur est requis et doit être valide.';
            }
            if (empty($smtp_from_name)) {
                $validation_errors[] = 'Le nom d\'expéditeur est requis.';
            }
            if (empty($admin_email) || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = 'L\'email administrateur est requis et doit être valide.';
            }
            if (empty($delai_relance_h) || (int)$delai_relance_h < 1) {
                $validation_errors[] = 'Le délai de relance doit être un nombre entier positif.';
            }

            if (empty($validation_errors)) {
                // Extraire le chemin depuis l'URL pour le format config.php
                $parsed = parse_url($base_url);
                $base_url_path = $parsed['path'] ?? '/workflow';
                $_SESSION['inst_config'] = [
                    'base_url'        => $base_url,
                    'base_url_path'   => $base_url_path,
                    'smtp_host'       => $smtp_host,
                    'smtp_port'       => (int)$smtp_port,
                    'smtp_from'       => $smtp_from,
                    'smtp_from_name'  => $smtp_from_name,
                    'admin_email'     => $admin_email,
                    'delai_relance_h' => (int)$delai_relance_h,
                ];
                $step = 3;
            } else {
                $error_messages = $validation_errors;
                $step = 2;
            }
        }

        // ── Action : Installer (écriture effective de config.php) ──
        elseif ($action === 'install') {
            $config = $_SESSION['inst_config'] ?? null;
            if ($config === null) {
                $error_messages[] = 'Session expirée. Veuillez recommencer la configuration.';
                $step = 1;
            } else {
                $write_result = inst_write_config($config);
                if ($write_result['success']) {
                    // Nettoyage de la session d'installation
                    unset($_SESSION['inst_csrf_token'], $_SESSION['inst_config']);
                    // Redirection vers index.php
                    header('Location: index.php');
                    exit;
                } else {
                    $error_messages[] = $write_result['message'];
                    $step = 3;
                }
            }
        }

        // ── Action : Retour à l'étape 2 depuis l'étape 3 ──
        elseif ($action === 'back_step2') {
            $step = 2;
        }

        else {
            $error_messages[] = 'Action non reconnue.';
        }
    }
}

// ── Valeurs par défaut pour le formulaire (étape 2) ─────────
$saved_config = $_SESSION['inst_config'] ?? [];
$default_base_url      = $saved_config['base_url'] ?? inst_detect_base_url();
$default_smtp_host     = $saved_config['smtp_host'] ?? INST_DEFAULT_SMTP_HOST;
$default_smtp_port     = $saved_config['smtp_port'] ?? 25;
$default_smtp_from     = $saved_config['smtp_from'] ?? INST_DEFAULT_SMTP_FROM;
$default_smtp_from_name = $saved_config['smtp_from_name'] ?? INST_DEFAULT_SMTP_FROM_NAME;
$default_admin_email   = $saved_config['admin_email'] ?? '';
$default_delai_relance_h = $saved_config['delai_relance_h'] ?? INST_DEFAULT_DELAI_RELANCE;

// Si on revient de l'étape 2 avec des valeurs POST, les utiliser
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $default_base_url      = trim($_POST['base_url'] ?? $default_base_url);
    $default_smtp_host     = trim($_POST['smtp_host'] ?? $default_smtp_host);
    $default_smtp_port     = trim((string)($_POST['smtp_port'] ?? $default_smtp_port));
    $default_smtp_from     = trim($_POST['smtp_from'] ?? $default_smtp_from);
    $default_smtp_from_name = trim($_POST['smtp_from_name'] ?? $default_smtp_from_name);
    $default_admin_email   = trim($_POST['admin_email'] ?? $default_admin_email);
    $default_delai_relance_h = trim((string)($_POST['delai_relance_h'] ?? $default_delai_relance_h));
}

// ── Étape 1 : vérification des prérequis ────────────────────
$prerequisites = inst_check_prerequisites();
$all_prereqs_ok = true;
foreach ($prerequisites as $check) {
    if (!$check['ok']) {
        $all_prereqs_ok = false;
        break;
    }
}

// ── Étape 3 : config à confirmer ────────────────────────────
$confirm_config = $_SESSION['inst_config'] ?? null;

// ── Rendu de la page (délégué à App\Render\InstallRenderer) ──
(new \App\Render\InstallRenderer())->renderPage([
    'step'            => $step,
    'messages'        => $messages,
    'error_messages'  => $error_messages,
    'prerequisites'   => $prerequisites,
    'all_prereqs_ok'  => $all_prereqs_ok,
    'confirm_config'  => $confirm_config,
    'install_dir'     => __DIR__,
    'defaults'        => [
        'base_url'        => $default_base_url,
        'smtp_host'       => $default_smtp_host,
        'smtp_port'       => $default_smtp_port,
        'smtp_from'       => $default_smtp_from,
        'smtp_from_name'  => $default_smtp_from_name,
        'admin_email'     => $default_admin_email,
        'delai_relance_h' => $default_delai_relance_h,
    ],
]);
