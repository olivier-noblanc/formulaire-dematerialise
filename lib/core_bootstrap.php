<?php
declare(strict_types=1);

/**
 * Core bootstrap — initialisation de l'application.
 *
 * Ce module est chargé en premier par helpers.php. Il :
 *   - require config.php
 *   - configure la session (cookies sécurisés, régénération ID)
 *   - définit la constante TEST_MODE (CLI vs web + secret)
 *   - active error_reporting(E_ALL)
 *   - vérifie les extensions PHP requises
 *   - charge les lib utilitaires (lib_uuid, lib_date, lib_html, lib_validation,
 *     lib_security)
 *   - charge PHPMailer (Exception, PHPMailer, SMTP)
 *
 * @package lib
 */

require_once __DIR__ . '/../config.php';

// Sécurité : configurer les flags de cookie de session
if (php_sapi_name() !== 'cli') {
    @ini_set("session.save_path", sys_get_temp_dir() . "/php-sessions");
    @mkdir(sys_get_temp_dir() . "/php-sessions", 0775, true);
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly'  => true,
        'samesite'  => 'Strict',
        'secure'    => $is_secure,
    ]);
}

// En mode CLI, la session n'est pas nécessaire (et génère des warnings)
if (php_sapi_name() !== 'cli') {
    @ini_set("session.save_path", sys_get_temp_dir() . "/php-sessions");
    @mkdir(sys_get_temp_dir() . "/php-sessions", 0775, true);
    session_start();
    // Sécurité (S-17) : régénérer l'ID de session au premier accès authentifié
    // pour prévenir la fixation de session. On ne le fait qu'une seule fois
    // par session (flag en session). require_admin() le fait aussi après élévation.
    if (empty($_SESSION['_session_initialized'])) {
        session_regenerate_id(true);
        $_SESSION['_session_initialized'] = true;
    }
} else {
    // Initialiser une session vide pour les scripts CLI qui utilisent $_SESSION
    // (alert_check.php, remind.php, tests)
    //
    // PHP 8.4+ : 'use_only_cookies' => false est DEPRECATED.
    // Pour les sessions CLI sans cookies, on utilise 'use_cookies' => false
    // seul. La directive 'use_only_cookies' ne doit pas être passée
    // explicitement à false (cf. https://www.php.net/manual/en/session.configuration.php)
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'use_cookies' => false,
        ]);
    }
}

// ── GESTION GLOBALE DES ERREURS — ERREURS TOUJOURS AFFICHÉES ─────
// Affiche le message, le fichier et la ligne en toutes circonstances.
set_exception_handler(function (\Throwable $e): never {
    error_log('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $msg = htmlspecialchars($e->getMessage());
    $file = htmlspecialchars($e->getFile());
    $trace = htmlspecialchars($e->getTraceAsString());
    if (class_exists(\App\Render\ErrorRenderer::class)) {
        (new \App\Render\ErrorRenderer())->errorPage(500, 'Erreur interne', $msg);
    } else {
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Erreur 500</title></head>'
           . '<body style="font-family:Arial,sans-serif;max-width:900px;margin:2rem auto;color:#222;">'
           . '<h1 style="color:#c0392b;">Erreur interne du serveur</h1>'
           . '<pre style="background:#f4f4f4;padding:1rem;overflow:auto;font-size:.85rem;border-left:4px solid #c0392b;white-space:pre-wrap;word-wrap:break-word;">'
           . $msg . "\n\n"
           . 'in ' . $file . ':' . $e->getLine() . "\n\n"
           . $trace
           . '</pre>'
           . '</body></html>';
    }
    exit(1);
});

// ── TEST MODE ──────────────────────────────────────────────────
// Activé par le header HTTP X-Test-Mode: 1
// Permet les tests automatisés via curl sans SMTP, sans CSRF, avec
// identification par header X-Test-User au lieu de AUTH_USER (IIS).
// Sécurité : TEST_MODE ne doit JAMAIS être activé par un simple header HTTP en production.
// En CLI (scripts de test), le header X-Test-Mode est autorisé.
// En contexte web, le header X-Test-Mode doit correspondre au secret APP_TEST_SECRET (env).
// Si APP_TEST_SECRET n'est pas défini, le header HTTP est ignoré en contexte web.
$_test_header = $_SERVER['HTTP_X_TEST_MODE'] ?? '';
$_test_env = !empty(getenv('APP_TEST_MODE'));
$_test_secret = getenv('APP_TEST_SECRET') !== false ? getenv('APP_TEST_SECRET') : '';
$_is_cli = php_sapi_name() === 'cli';
if ($_is_cli && !empty($_test_header)) {
    // CLI : activation par header (scripts de test via php -S)
    define('TEST_MODE', true);
} elseif (!empty($_test_header) && $_test_env && !empty($_test_secret) && hash_equals($_test_secret, $_test_header)) {
    // Web : activation par header + secret partagé (requiert APP_TEST_MODE et APP_TEST_SECRET)
    define('TEST_MODE', true);
    error_log('[SECURITY] TEST_MODE activated via HTTP with valid secret from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
} elseif ($_test_env && empty($_test_header)) {
    // Web : activation uniquement par variable d'environnement (pas de header)
    define('TEST_MODE', true);
} else {
    define('TEST_MODE', false);
}

// ── AFFICHAGE DES ERREURS — TOUJOURS ACTIF ──────────────────────
// L'utilisateur veut voir toutes les erreurs à l'écran, même en prod.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');

// En mode test (JSON), on garde display_errors à 0 pour ne pas corrompre le JSON
if (TEST_MODE) {
    ini_set('display_errors', '0');
}

// ── EXTENSIONS REQUISES ────────────────────────────────────────
// Note : 'sqlite3' (extension procédurale) est optionnelle — seul 'pdo_sqlite' est requis
// pour le fonctionnement de l'application. L'extension sqlite3 n'est utilisée nulle part
// dans le code métier (tout passe par PDO).
$required_extensions = ['mbstring', 'pdo_sqlite', 'json', 'session', 'pcre'];
$missing_extensions = array_filter($required_extensions, fn(string $ext) => !extension_loaded($ext));
if (!empty($missing_extensions)) {
    // health.php peut quand même tourner pour signaler le problème
    $script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '');
    $is_health_check = ($script === 'index.php?p=health');
    if (!$is_health_check) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreurs de configuration : extensions PHP manquantes : " . implode(', ', $missing_extensions) . "\n";
        echo "Installez-les avec : sudo apt-get install php-" . implode(' php-', $missing_extensions) . "\n";
        exit(1);
    }
}

// File d'attente des mails interceptés en mode test (acces global)
$GLOBALS['_test_mails'] = [];

// Base de données test séparée pour ne pas polluer la vraie DB
if (TEST_MODE) {
    $test_db_path = __DIR__ . '/../db/workflow_test.db';
    // Définir DB_PATH avant que config.php ne soit déjà chargé — on override via constante
    // Comme DB_PATH est déjà définie, on ne peut pas la redéfinir. On utilise un flag global.
    $GLOBALS['_test_db_path'] = $test_db_path;
}
// Tentative d'inclusion de vendor/autoload.php, mais ignorée si non présente
require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;

// ═══════════════════════════════════════════════════════════════
// ARCHITECTURE MODULAIRE — Phase 1 (S3-CTO)
// ═══════════════════════════════════════════════════════════════
// helpers.php était historiquement un « god file » (3867 lignes / 108 fonctions
// en v5.22.0). Depuis S3 (Phase 1), il est progressivement découpé en modules
// procéduraux lib_*.php chargés via require_once ci-dessous. Les fonctions
// restent disponibles globalement (pas de namespace, pas de classe) — tous
// les `require 'helpers.php'` existants continuent de fonctionner à l'identique.
//
// Plan de découpage en 3 phases (CTO, REUNION1-CTO §4) :
//   - Phase 1 (S3 — cette version) : fonctions autonomes peu couplées.
//     Modules livrés : lib_uuid, lib_date, lib_html, lib_validation,
//     lib_security (CSRF uniquement). 17 fonctions extraites.
//     Note : send_security_headers() et security_log() restent ici car des
//     tests de test_unit.php (§12.12, §12.13) inspectent le code source de
//     helpers.php pour vérifier la présence de la définition de security_log
//     et le corps de la définition de send_security_headers. Les déplacer
//     en Phase 1 aurait cassé ces 11 tests — violation de la contrainte
//     « 0 breaking change / ne pas modifier les tests ». Leur extraction
//     est donc reportée en Phase 2, après refactoring de ces tests
//     d'inspection source pour qu'ils parcourent l'ensemble des lib_*.php.
//   - Phase 2 (S4) : fonctions medium-coupling (workflow, mail, LDAP, RGPD)
//     + extraction différée de send_security_headers() et security_log().
//   - Phase 3 (S5+) : fonctions à couplage fort (DB, cache, settings).
//
// Règle : 0 breaking change. Les signatures et comportements des fonctions
// sont strictement préservés — seul l'emplacement physique change.
// ═══════════════════════════════════════════════════════════════
require_once __DIR__ . '/../classes/DatabaseMigrations.php';
