<?php
require_once __DIR__ . '/vendor/autoload.php';
/**
 * helpers.php — Façade de chargement des modules lib/
 *
 * Point d'entrée unique pour tous les consommateurs.
 * Charge les modules lib/ dans le bon ordre + enregistre les services OOP.
 *
 * @package Facade
 */

// ── 1. Bootstrap (session, TEST_MODE, extensions, config.php) ──
// config.php DOIT être chargé AVANT d'instancier Database (car Database utilise DB_PATH)
require_once __DIR__ . '/lib/core_bootstrap.php';

// ── 1b. resolve_base_url() — fallback pour les serveurs avec ancien config.php ──
// config.php est un fichier PROTÉGÉ (update.ps1 ne l'écrase jamais). Si le serveur
// a un ancien config.php (avant v7.6.0), la fonction resolve_base_url() n'existe pas.
// On la définit ici (dans helpers.php qui, lui, est mis à jour) pour que les emails
// puissent construire des URLs correctes même avec un ancien config.php.
//
// L'ancien config.php définit BASE_URL avec un fallback 'localhost' + '/workflow'
// hardcodé. Cette fonction utilise la détection automatique (HTTP_HOST) en web,
// et lit le setting 'base_url' en DB en CLI.
if (!function_exists('resolve_base_url')) {
    function resolve_base_url(): string {
        // En contexte web, utiliser BASE_URL si elle ressemble à une vraie URL
        // (pas localhost). Sinon, détecter depuis HTTP_HOST.
        if (php_sapi_name() !== 'cli') {
            $base = defined('BASE_URL') ? BASE_URL : '';

            // Si BASE_URL contient localhost, la détecter dynamiquement
            if (strpos($base, 'localhost') !== false || $base === '') {
                $protocol = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
                if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
                    $protocol = strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https' ? 'https' : 'http';
                }
                $host = '';
                if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
                    $hosts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']));
                    $host = $hosts[0];
                } elseif (!empty($_SERVER['HTTP_HOST'])) {
                    $host = $_SERVER['HTTP_HOST'];
                } elseif (!empty($_SERVER['SERVER_NAME'])) {
                    $host = $_SERVER['SERVER_NAME'];
                }
                // Détecter le path depuis SCRIPT_NAME
                $path = '';
                if (!empty($_SERVER['SCRIPT_NAME'])) {
                    $dir = dirname($_SERVER['SCRIPT_NAME']);
                    if ($dir !== '/' && $dir !== '\\' && $dir !== '.') {
                        $path = $dir;
                    }
                }
                return $protocol . '://' . $host . $path;
            }
            return $base;
        }

        // En CLI, essayer le setting 'base_url' en DB
        static $cached = null;
        if ($cached === null) {
            try {
                $dbPath = defined('DB_PATH') ? DB_PATH : DEFAULT_DB_PATH;
                $pdo = new PDO('sqlite:' . $dbPath);
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
                $stmt->execute(['base_url']);
                $val = $stmt->fetchColumn();
                $cached = $val ?? (defined('BASE_URL') ? BASE_URL : 'http://localhost');
            } catch (\Throwable $e) {
                $cached = defined('BASE_URL') ? BASE_URL : 'http://localhost';
            }
        }
        return $cached;
    }
}

// ── 2. Enregistrer les services OOP dans le container DI ──
// Maintenant que config.php est chargé (DB_PATH défini), on peut instancier Database
$_app = \App\Core\App::getInstance();
$_db_service = new \App\Core\Database();
$_app->set(\App\Core\Database::class, $_db_service);

// ── 3. Services OOP et wrappers procéduraux ──
// Charge toutes les fonctions globales (uuid, date, slug, jargon, cache, settings,
// persona, conditions, validation, test_mode, database) en un seul fichier.
require_once __DIR__ . '/src/lib_wrappers.php';

// ── 3b. get_pdo() / release_pdo() — wrappers PDO procéduraux ──
// Définis ICI (dans helpers.php qui est dans l'allowIn de la règle
// disallowed-calls) plutôt que dans src/lib_wrappers.php, car ils
// appellent App::db()->getPdo() qui est interdit hors de la couche
// Repository. Les scripts legacy (alert_check.php, remind.php, tests)
// continuent à utiliser get_pdo() comme avant — la définition est juste
// déplacée dans un fichier autorisé.
if (!function_exists('get_pdo')) {
    function get_pdo(): PDO {
        return \App\Core\App::db()->getPdo();
    }
}
if (!function_exists('release_pdo')) {
    function release_pdo(): void {
        \App\Core\App::db()->release();
    }
}

// send_mail(), build_mail_html(), render_email_template(), format_bytes() :
// wrappers manquants à l'exécution réelle (n'existaient qu'en stub PHPStan) —
// voir CHANGELOG et src/mail_wrappers.php pour le détail du bug.
require_once __DIR__ . '/src/mail_wrappers.php';

// ── 6. Sécurité (headers) ──

// Enregistrer SecurityService AVANT d'appeler send_security_headers()
// HtmlService doit être enregistré avant (SecurityService en dépend)
if (!$_app->has(\App\Render\HtmlService::class)) {
    $_app->set(\App\Render\HtmlService::class, new \App\Render\HtmlService());
}
$_app->set(\App\Render\DynamicCssService::class, new \App\Render\DynamicCssService());
$_html_svc = $_app->get(\App\Render\HtmlService::class);
$_app->set(\App\Security\SecurityService::class, new \App\Security\SecurityService($_html_svc));

// ── 6b. Envoyer les headers de sécurité le plus tôt possible ──
if (php_sapi_name() !== 'cli') {
    $_app->get(\App\Security\SecurityService::class)->sendSecurityHeaders();
}

// ═══════════════════════════════════════════════════════════════
// BOOTSTRAP OOP — enregistrer les services restants dans le container DI
// (Database et Config déjà enregistrés en haut)
// ═══════════════════════════════════════════════════════════════
$_auth_svc = new \App\Auth\AuthService($_db_service);
$_app->set(\App\Auth\AuthService::class, $_auth_svc);
$_app->set(\App\Repository\SettingsRepository::class, new \App\Repository\SettingsRepository($_db_service));
$_settings_repo = $_app->get(\App\Repository\SettingsRepository::class);
$_app->set(\App\Settings\SettingsService::class, new \App\Settings\SettingsService($_settings_repo));
$_app->set(\App\Cache\CacheService::class, new \App\Cache\CacheService());
$_app->set(\App\Repository\AuditRepository::class, new \App\Repository\AuditRepository($_db_service));
$_audit_repo = $_app->get(\App\Repository\AuditRepository::class);
$_app->set(\App\Audit\AuditLogService::class, new \App\Audit\AuditLogService($_audit_repo));
$_app->set(\App\Repository\AdminRepository::class, new \App\Repository\AdminRepository($_db_service, $_settings_repo));
$_app->set(\App\Repository\FormRepository::class, new \App\Repository\FormRepository($_db_service));
$_app->set(\App\Repository\SubmissionRepository::class, new \App\Repository\SubmissionRepository($_db_service));
$_app->set(\App\Repository\TokenRepository::class, new \App\Repository\TokenRepository($_db_service));
$_app->set(\App\Repository\AttachmentRepository::class, new \App\Repository\AttachmentRepository($_db_service));
$_app->set(\App\Repository\AlertRepository::class, new \App\Repository\AlertRepository($_db_service));
$_app->set(\App\Repository\DelegationRepository::class, new \App\Repository\DelegationRepository($_db_service));
$_app->set(\App\Repository\PersonaTokenRepository::class, new \App\Repository\PersonaTokenRepository($_db_service));
$_app->set(\App\Repository\LazyCronRepository::class, new \App\Repository\LazyCronRepository($_db_service));
// FieldService DOIT être instancié APRÈS FormRepository + SubmissionRepository
// (son constructeur les résout via App::getInstance()->get() si non passés)
$_app->set(\App\Forms\FieldService::class, new \App\Forms\FieldService($_db_service));
$_app->set(\App\Render\HtmlService::class, new \App\Render\HtmlService());
$_app->set(\App\Workflow\ConditionEvaluator::class, new \App\Workflow\ConditionEvaluator());
$_settings_svc = $_app->get(\App\Settings\SettingsService::class);
$_mail_repo = new \App\Repository\MailRepository($_db_service);
$_app->set(\App\Repository\MailRepository::class, $_mail_repo);
$_mail_svc = new \App\Mail\MailService($_mail_repo, $_settings_svc);
$_app->set(\App\Mail\MailService::class, $_mail_svc);
$_auth_svc->setMailer($_mail_svc);
$_fields_svc = $_app->get(\App\Forms\FieldService::class);
$_conditions_svc = $_app->get(\App\Workflow\ConditionEvaluator::class);
$_workflow_svc = new \App\Workflow\WorkflowEngine($_db_service, $_settings_svc, $_mail_svc, $_fields_svc, $_conditions_svc, $_app->get(\App\Repository\SubmissionRepository::class), $_app->get(\App\Repository\TokenRepository::class), $_app->get(\App\Repository\FormRepository::class));
$_app->set(\App\Workflow\WorkflowEngine::class, $_workflow_svc);
$_html_svc = $_app->get(\App\Render\HtmlService::class);
$_app->set(\App\Persona\PersonaService::class, new \App\Persona\PersonaService($_db_service));
$_app->set(\App\Stats\StatsService::class, new \App\Stats\StatsService($_db_service));
$_app->set(\App\Rgpd\RgpdService::class, new \App\Rgpd\RgpdService($_db_service, $_app->get(\App\Repository\SubmissionRepository::class), $_app->get(\App\Repository\TokenRepository::class), $_app->get(\App\Repository\AttachmentRepository::class), $_app->get(\App\Repository\AlertRepository::class), $_app->get(\App\Repository\AdminRepository::class), $_app->get(\App\Repository\DelegationRepository::class)));
$_app->set(\App\Token\TokenService::class, new \App\Token\TokenService(
    $_db_service,
    $_settings_svc,
    $_app->get(\App\Auth\AuthService::class),
    $_app->get(\App\Audit\AuditLogService::class),
    $_mail_svc,
    $_app->get(\App\Repository\SubmissionRepository::class),
    $_app->get(\App\Repository\TokenRepository::class),
    $_app->get(\App\Repository\DelegationRepository::class)
));
$_app->set(\App\Forms\ValidatorDataService::class, new \App\Forms\ValidatorDataService($_app->get(\App\Repository\SubmissionRepository::class), $_app->get(\App\Repository\FormRepository::class), $_app->get(\App\Forms\FieldService::class)));
$_attachment_repo = $_app->get(\App\Repository\AttachmentRepository::class);
$_app->set(\App\Attachment\AttachmentService::class, new \App\Attachment\AttachmentService($_attachment_repo));
$_app->set(\App\Cron\CronService::class, new \App\Cron\CronService($_db_service, $_app->get(\App\Repository\LazyCronRepository::class)));
$_app->set(\App\Validation\ValidationService::class, new \App\Validation\ValidationService());
$_app->set(\App\Export\ExportService::class, new \App\Export\ExportService($_db_service, $_app->get(\App\Auth\AuthService::class), $_app->get(\App\Repository\SubmissionRepository::class)));
$_app->set(\App\Email\EmailVerificationService::class, new \App\Email\EmailVerificationService($_app->get(\App\Cache\CacheService::class)));
$_app->set(\App\Docs\DocumentationService::class, new \App\Docs\DocumentationService());
