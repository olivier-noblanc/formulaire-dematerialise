<?php
/**
 * install.php — Assistant d'installation de premier lancement
 *
 * Ce fichier est entièrement autonome : il ne dépend ni de config.php
 * ni de helpers.php ni de style.php, car ces fichiers nécessitent
 * une configuration déjà en place. Il intègre son propre CSS inline
 * et sa propre gestion CSRF.
 *
 * Étapes :
 *   1. Vérification des prérequis (PHP ≥ 8.0, SQLite3, PDO SQLite, intl, écriture, PHPMailer)
 *   2. Formulaire de configuration (BASE_URL, SMTP, admin, délai)
 *   3. Confirmation et écriture de config.php
 */

// ── Sécurité de base ──────────────────────────────────────────
session_start();
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

// ── Si config.php existe déjà, rediriger vers index.php ──────
if (file_exists(__DIR__ . '/config.php')) {
    header('Location: index.php');
    exit;
}

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
        $mail->Subject = 'Test SMTP — Installation Workflow DREETS';
        $mail->Body    = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test d\'envoi d\'email</h2>
  <p>Cet email a été envoyé depuis l\'assistant d\'installation du Workflow DREETS.</p>
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
        . "\$protocol = isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] === 'on' ? 'https' : 'http';\n"
        . "\$hostname = \$_SERVER['HTTP_HOST'] ?? 'localhost';\n"
        . "define('BASE_URL',       \$protocol . '://' . \$hostname . '" . addslashes($values['base_url_path']) . "');\n"
        . "define('DB_PATH',        __DIR__ . '/db/workflow.db');\n"
        . "define('SMTP_HOST',      '" . addslashes($values['smtp_host']) . "');\n"
        . "define('SMTP_PORT',      " . (int)$values['smtp_port'] . ");\n"
        . "define('SMTP_FROM',      '" . addslashes($values['smtp_from']) . "');\n"
        . "define('SMTP_FROM_NAME', '" . addslashes($values['smtp_from_name']) . "');\n"
        . "define('DELAI_RELANCE_H', " . (int)$values['delai_relance_h'] . ");\n"
        . "// Email de l'administrateur principal\n"
        . "define('ADMIN_EMAIL',    '" . addslashes($values['admin_email']) . "');\n"
        . "// Version de l'application — à mettre à jour à chaque release\n"
        . "define('APP_VERSION',    '3.0.0');\n"
        . "date_default_timezone_set('Europe/Paris');\n";

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
            $smtp_host    = trim($_POST['smtp_host'] ?? 'smtp.social.gouv.fr');
            $smtp_port    = (int)($_POST['smtp_port'] ?? 25);
            $smtp_from    = trim($_POST['smtp_from'] ?? 'workflow@dreets.gouv.fr');
            $smtp_from_name = trim($_POST['smtp_from_name'] ?? 'Workflow DREETS');
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
$default_smtp_host     = $saved_config['smtp_host'] ?? 'smtp.social.gouv.fr';
$default_smtp_port     = $saved_config['smtp_port'] ?? 25;
$default_smtp_from     = $saved_config['smtp_from'] ?? 'workflow@dreets.gouv.fr';
$default_smtp_from_name = $saved_config['smtp_from_name'] ?? 'Workflow DREETS';
$default_admin_email   = $saved_config['admin_email'] ?? '';
$default_delai_relance_h = $saved_config['delai_relance_h'] ?? 48;

// Si on revient de l'étape 2 avec des valeurs POST, les utiliser
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $default_base_url      = trim($_POST['base_url'] ?? $default_base_url);
    $default_smtp_host     = trim($_POST['smtp_host'] ?? $default_smtp_host);
    $default_smtp_port     = trim($_POST['smtp_port'] ?? $default_smtp_port);
    $default_smtp_from     = trim($_POST['smtp_from'] ?? $default_smtp_from);
    $default_smtp_from_name = trim($_POST['smtp_from_name'] ?? $default_smtp_from_name);
    $default_admin_email   = trim($_POST['admin_email'] ?? $default_admin_email);
    $default_delai_relance_h = trim($_POST['delai_relance_h'] ?? $default_delai_relance_h);
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation — Workflow DREETS</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
    <style>
        /* ── Reset & Base ──────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; min-height: 100vh; display: flex; flex-direction: column; }

        /* ── Bandeau ──────────────────────────────────────────── */
        .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
        .bandeau a { color: #b3c8f0; font-size: .8rem; text-decoration: none; }

        /* ── Container ────────────────────────────────────────── */
        .container { max-width: 800px; margin: 0 auto; padding: 0 1rem 2rem; width: 100%; flex: 1; }

        /* ── Typography ───────────────────────────────────────── */
        h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
        h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1rem; }

        /* ── Cards ────────────────────────────────────────────── */
        .card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; }

        /* ── Buttons ──────────────────────────────────────────── */
        .btn { padding: .5rem 1rem; border: none; border-radius: 3px; font-size: .85rem; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #003189; color: #fff; }
        .btn-primary:hover { background: #002270; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-danger { background: #c0392b; color: #fff; }
        .btn-danger:hover { background: #a93226; }
        .btn-test { background: #27ae60; color: #fff; }
        .btn-test:hover { background: #219a52; }
        .btn:disabled { opacity: .55; cursor: not-allowed; }

        /* ── Messages ─────────────────────────────────────────── */
        .msg-success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1a6b3c; }
        .msg-error { background: #ffebee; border: 1px solid #c0392b; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #c0392b; }
        .msg-info { background: #e3f2fd; border: 1px solid #1976d2; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1565c0; }

        /* ── Form fields ──────────────────────────────────────── */
        .field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1rem; }
        label { font-size: .85rem; font-weight: bold; color: #444; }
        .hint { font-size: .75rem; color: #888; font-weight: normal; }
        .req { color: #c0392b; margin-left: 2px; }
        input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], select, textarea {
            width: 100%; padding: .5rem .75rem; border: 1px solid #aaa;
            border-radius: 3px; font-size: .9rem; font-family: inherit; background: #fff; color: #1e1e1e;
        }
        input:focus, select:focus, textarea:focus { outline: 2px solid #003189; outline-offset: 1px; border-color: #003189; }
        .field-error { border-color: #c0392b !important; background: #fff5f5; }

        /* ── Form actions ─────────────────────────────────────── */
        .form-actions { display: flex; gap: .5rem; margin-top: 1rem; flex-wrap: wrap; }

        /* ── Stepper ──────────────────────────────────────────── */
        .stepper { display: flex; align-items: center; justify-content: center; margin-bottom: 2rem; gap: 0; }
        .step-item { display: flex; flex-direction: column; align-items: center; min-width: 100px; max-width: 180px; flex: 1; text-align: center; position: relative; padding: 0 .5rem; }
        .step-item:not(:last-child)::after { content: ''; position: absolute; top: 18px; right: -50%; width: 100%; height: 3px; z-index: 0; }
        .step-item.step-done:not(:last-child)::after { background: #1a6b3c; }
        .step-item.step-active:not(:last-child)::after { background: #b45309; }
        .step-item.step-upcoming:not(:last-child)::after { background: #ccc; }
        .step-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .95rem; font-weight: bold; z-index: 1; margin-bottom: .5rem; }
        .step-done .step-icon { background: #1a6b3c; color: #fff; }
        .step-active .step-icon { background: #003189; color: #fff; }
        .step-upcoming .step-icon { background: #ccc; color: #666; }
        .step-label { font-size: .78rem; font-weight: bold; color: #333; margin-bottom: .15rem; line-height: 1.3; }
        .step-upcoming .step-label { color: #999; }

        /* ── Prerequisite checks ──────────────────────────────── */
        .check-list { list-style: none; padding: 0; }
        .check-item { display: flex; align-items: flex-start; gap: .75rem; padding: .75rem 0; border-bottom: 1px solid #eee; font-size: .9rem; }
        .check-item:last-child { border-bottom: none; }
        .check-icon { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .85rem; font-weight: bold; flex-shrink: 0; margin-top: 1px; }
        .check-ok .check-icon { background: #1a6b3c; color: #fff; }
        .check-fail .check-icon { background: #c0392b; color: #fff; }
        .check-label { font-weight: bold; color: #333; }
        .check-detail { font-size: .8rem; color: #888; margin-top: .15rem; }

        /* ── Config preview (step 3) ──────────────────────────── */
        .config-preview { background: #f7f7fb; border: 1px solid #ddd; border-radius: 4px; padding: 1.25rem; font-family: "Consolas", "Monaco", monospace; font-size: .82rem; line-height: 1.7; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
        .config-preview .config-key { color: #003189; font-weight: bold; }
        .config-preview .config-val { color: #1a6b3c; }

        /* ── Warning box ──────────────────────────────────────── */
        .warn-box { background: #fff3e0; border-left: 4px solid #b45309; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; font-size: .9rem; color: #7c4700; }

        /* ── Footer ───────────────────────────────────────────── */
        .footer { text-align: center; padding: 1.5rem; font-size: .78rem; color: #999; margin-top: auto; border-top: 1px solid #eee; }

        /* ── Responsive ───────────────────────────────────────── */
        @media (max-width: 600px) {
            .stepper { gap: 0; }
            .step-item { min-width: 70px; padding: 0 .25rem; }
            .step-label { font-size: .7rem; }
            .bandeau { padding: .5rem 1rem; font-size: .78rem; }
        }
    </style>
</head>
<body>

<div class="bandeau">
    <strong>DREETS</strong> — Assistant d'installation
</div>

<div class="container">

    <!-- Stepper -->
    <div class="stepper">
        <div class="step-item <?= $step >= 1 ? ($step > 1 ? 'step-done' : 'step-active') : 'step-upcoming' ?>">
            <div class="step-icon"><?= $step > 1 ? '✓' : '1' ?></div>
            <div class="step-label">Prérequis</div>
        </div>
        <div class="step-item <?= $step >= 2 ? ($step > 2 ? 'step-done' : 'step-active') : 'step-upcoming' ?>">
            <div class="step-icon"><?= $step > 2 ? '✓' : '2' ?></div>
            <div class="step-label">Configuration</div>
        </div>
        <div class="step-item <?= $step >= 3 ? 'step-active' : 'step-upcoming' ?>">
            <div class="step-icon">3</div>
            <div class="step-label">Installation</div>
        </div>
    </div>

    <h1><span aria-hidden="true">🔧</span> Installation du Workflow DREETS</h1>

    <?php foreach ($messages as $msg): ?>
        <div class="msg-success"><?= inst_h($msg) ?></div>
    <?php endforeach; ?>

    <?php foreach ($error_messages as $msg): ?>
        <div class="msg-error"><?= inst_h($msg) ?></div>
    <?php endforeach; ?>

    <?php if ($step === 1): ?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 1 : Vérification des prérequis
         ════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>Étape 1 — Vérification des prérequis</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            L'assistant vérifie que votre environnement répond aux exigences minimales pour faire fonctionner le Workflow DREETS.
        </p>
        <ul class="check-list">
            <?php foreach ($prerequisites as $key => $check): ?>
            <li class="check-item <?= $check['ok'] ? 'check-ok' : 'check-fail' ?>">
                <div class="check-icon"><?= $check['ok'] ? '✓' : '✗' ?></div>
                <div>
                    <div class="check-label"><?= inst_h($check['label']) ?></div>
                    <div class="check-detail"><?= inst_h($check['detail']) ?></div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($all_prereqs_ok): ?>
    <form method="POST">
        <?= inst_csrf_field() ?>
        <input type="hidden" name="action" value="to_step2">
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Continuer vers la configuration →</button>
        </div>
    </form>
    <?php else: ?>
    <div class="warn-box">
        <span aria-hidden="true">⚠</span> Certains prérequis ne sont pas satisfaits. Corrigez les problèmes ci-dessus puis rechargez cette page.
    </div>
    <form method="GET">
        <div class="form-actions">
            <button type="submit" class="btn btn-secondary">↻ Recharger la page</button>
        </div>
    </form>
    <?php endif; ?>

    <?php elseif ($step === 2): ?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 2 : Formulaire de configuration
         ════════════════════════════════════════════════════════════ -->

    <div class="card">
        <h2>Étape 2 — Configuration de l'application</h2>

        <form method="POST" id="config-form">
            <?= inst_csrf_field() ?>
            <input type="hidden" name="action" value="generate_config">

            <!-- Base URL -->
            <div class="field">
                <label>URL de base <span class="req">*</span> <span class="hint">(auto-détectée, modifiable)</span></label>
                <input type="text" name="base_url" value="<?= inst_h($default_base_url) ?>" placeholder="https://serveur.intra/workflow">
            </div>

            <!-- SMTP -->
            <div class="field">
                <label>Hôte SMTP <span class="req">*</span></label>
                <input type="text" name="smtp_host" value="<?= inst_h($default_smtp_host) ?>" placeholder="smtp.social.gouv.fr">
            </div>

            <div class="field">
                <label>Port SMTP <span class="req">*</span></label>
                <input type="number" name="smtp_port" value="<?= inst_h((string)$default_smtp_port) ?>" min="1" max="65535">
            </div>

            <div class="field">
                <label>Email expéditeur <span class="req">*</span></label>
                <input type="email" name="smtp_from" value="<?= inst_h($default_smtp_from) ?>" placeholder="workflow@dreets.gouv.fr">
            </div>

            <div class="field">
                <label>Nom de l'expéditeur <span class="req">*</span></label>
                <input type="text" name="smtp_from_name" value="<?= inst_h($default_smtp_from_name) ?>" placeholder="Workflow DREETS">
            </div>

            <!-- Admin -->
            <div class="field">
                <label>Email administrateur <span class="req">*</span> <span class="hint">(adresse de l'administrateur principal)</span></label>
                <input type="email" name="admin_email" value="<?= inst_h($default_admin_email) ?>" placeholder="prenom.nom@dreets.gouv.fr" required>
            </div>

            <!-- Délai -->
            <div class="field">
                <label>Délai de relance (heures) <span class="hint">(délai avant envoi d'une relance automatique)</span></label>
                <input type="number" name="delai_relance_h" value="<?= inst_h((string)$default_delai_relance_h) ?>" min="1">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Générer config.php →</button>
                <a href="install.php?step=1" class="btn btn-secondary">← Retour</a>
            </div>
        </form>
        </div>

    <!-- Test SMTP (formulaire séparé) -->
    <div class="card" style="margin-top:1.5rem;">
        <h2>Test d'envoi SMTP</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            Envoyer un email de test pour vérifier que la configuration SMTP est correcte avant de valider l'installation.
            L'email sera envoyé à l'adresse administrateur indiquée ci-dessus.
        </p>
        <form method="POST">
            <?= inst_csrf_field() ?>
            <input type="hidden" name="action" value="test_smtp">
            <input type="hidden" name="base_url" value="<?= inst_h($default_base_url) ?>">
            <input type="hidden" name="smtp_host" value="<?= inst_h($default_smtp_host) ?>">
            <input type="hidden" name="smtp_port" value="<?= inst_h((string)$default_smtp_port) ?>">
            <input type="hidden" name="smtp_from" value="<?= inst_h($default_smtp_from) ?>">
            <input type="hidden" name="smtp_from_name" value="<?= inst_h($default_smtp_from_name) ?>">
            <input type="hidden" name="admin_email" value="<?= inst_h($default_admin_email) ?>">
            <input type="hidden" name="delai_relance_h" value="<?= inst_h((string)$default_delai_relance_h) ?>">
            <button type="submit" class="btn btn-test" <?= empty($default_admin_email) ? 'disabled' : '' ?>><span aria-hidden="true">📧</span> Envoyer un email de test</button>
            <?php if (empty($default_admin_email)): ?>
                <span class="hint" style="margin-left:.5rem;">Renseignez l'email administrateur ci-dessus d'abord.</span>
            <?php endif; ?>
        </form>
    </div>

    <?php elseif ($step === 3): ?>
    <!-- ════════════════════════════════════════════════════════════
         ÉTAPE 3 : Confirmation et installation
         ════════════════════════════════════════════════════════════ -->

    <?php if ($confirm_config === null): ?>
    <div class="msg-error">
        Aucune configuration trouvée en session. Veuillez recommencer depuis l'étape 1.
    </div>
    <a href="install.php?step=1" class="btn btn-primary">← Recommencer</a>

    <?php else: ?>
    <div class="card">
        <h2>Étape 3 — Confirmation de l'installation</h2>
        <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
            Vérifiez la configuration ci-dessous. Si tout est correct, cliquez sur « Installer » pour créer le fichier
            <strong>config.php</strong> et lancer l'application.
        </p>

        <div class="config-preview"><?php
            $config_lines = [
                'BASE_URL'       => $confirm_config['base_url'],
                'DB_PATH'        => '__DIR__ . \'/db/workflow.db\'',
                'SMTP_HOST'      => $confirm_config['smtp_host'],
                'SMTP_PORT'      => (string)$confirm_config['smtp_port'],
                'SMTP_FROM'      => $confirm_config['smtp_from'],
                'SMTP_FROM_NAME' => $confirm_config['smtp_from_name'],
                'DELAI_RELANCE_H'=> (string)$confirm_config['delai_relance_h'],
                'ADMIN_EMAIL'    => $confirm_config['admin_email'],
                'APP_VERSION'    => '3.0.0',
            ];
            foreach ($config_lines as $key => $val):
                echo '<span class="config-key">' . inst_h($key) . '</span> = <span class="config-val">' . inst_h($val) . "</span>\n";
            endforeach;
        ?></div>
    </div>

    <div class="warn-box">
        <span aria-hidden="true">⚠</span> En cliquant sur « Installer », le fichier <strong>config.php</strong> sera créé dans le répertoire
        <code><?= inst_h(__DIR__) ?></code> et le répertoire <strong>db/</strong> sera créé si nécessaire.
        L'application sera alors accessible via <a href="index.php" style="color:#7c4700;font-weight:bold;">index.php</a>.
    </div>

    <form method="POST">
        <?= inst_csrf_field() ?>
        <input type="hidden" name="action" value="install">
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">✓ Installer</button>
            <a href="install.php?step=2" class="btn btn-secondary">← Modifier la configuration</a>
        </div>
    </form>

    <?php endif; ?>

    <?php endif; ?>

</div>

<div class="footer">
    Workflow DREETS — Assistant d'installation · Version 3.0.0
</div>

</body>
</html>
