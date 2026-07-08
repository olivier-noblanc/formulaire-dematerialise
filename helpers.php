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
                $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/workflow.db';
                $pdo = new PDO('sqlite:' . $dbPath);
                $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
                $stmt->execute(['base_url']);
                $val = $stmt->fetchColumn();
                $cached = $val ?: (defined('BASE_URL') ? BASE_URL : 'http://localhost');
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
$_app->set(\App\Core\Config::class, new \App\Core\Config());

// ── 3. Base de données (PDO + migrations) ──
// get_pdo() délègue à App::db() — le service est déjà enregistré ci-dessus
require_once __DIR__ . '/lib/database.php';

// ── 4. Auth & admin users ──
require_once __DIR__ . '/lib/auth.php';

// ── 5. Settings + cache ──
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/cache.php';

// ── 6. Sécurité (headers + CSRF) ──
require_once __DIR__ . '/lib/security.php';

// Enregistrer SecurityService AVANT d'appeler send_security_headers()
// HtmlService doit être enregistré avant (SecurityService en dépend)
if (!$_app->has(\App\Render\HtmlService::class)) {
    $_app->set(\App\Render\HtmlService::class, new \App\Render\HtmlService());
}
$_html_svc = $_app->get(\App\Render\HtmlService::class);
$_app->set(\App\Security\SecurityService::class, new \App\Security\SecurityService($_html_svc));

// Envoyer les headers de sécurité le plus tôt possible
if (php_sapi_name() !== 'cli') {
    send_security_headers();
}

// ── 7. Logging (audit + security) ──
require_once __DIR__ . '/lib/audit_log.php';

// ── 8. Mode test ──
require_once __DIR__ . '/lib/test_mode.php';

// ── 9. Email (vérification LDAP/SMTP + envoi) ──
require_once __DIR__ . '/lib/email_verify.php';
require_once __DIR__ . '/lib/mail.php';

// ── 10. Moteur workflow + filled_by + conditions ──
require_once __DIR__ . '/lib/workflow.php';
require_once __DIR__ . '/lib/filled_by.php';
require_once __DIR__ . '/lib/conditions.php';

// ── 11. Tokens (regenerate, cancel, remind, delegate) ──
require_once __DIR__ . '/lib/tokens.php';

// ── 12. Pièces jointes ──
require_once __DIR__ . '/lib/attachments.php';

// ── 13. RGPD ──
require_once __DIR__ . '/lib/rgpd.php';

// ── 14. Statistiques + recherche ──
require_once __DIR__ . '/lib/stats.php';

// ── 15. Webhook + DB size ──
require_once __DIR__ . '/lib/webhook.php';

// ── 16. Export CSV ──
require_once __DIR__ . '/lib/export_csv.php';

// ── 17. Lazy cron + POST handler ──
require_once __DIR__ . '/lib/lazy_cron.php';

// ── 17b. Persona (refonte v10.0.0 — token-based) ──
require_once __DIR__ . '/lib/persona.php';

// ── 18. UI — navigation, errors, form, jargon, ldap ──
require_once __DIR__ . '/lib/render_navigation.php';
require_once __DIR__ . '/lib/render_errors.php';
require_once __DIR__ . '/lib/render_form.php';
require_once __DIR__ . '/lib/render_ldap.php';
require_once __DIR__ . '/lib/jargon.php';

// ═══════════════════════════════════════════════════════════════
// BOOTSTRAP OOP — enregistrer les services restants dans le container DI
// (Database et Config déjà enregistrés en haut)
// ═══════════════════════════════════════════════════════════════
$_app->set(\App\Auth\AuthService::class, new \App\Auth\AuthService($_db_service));
$_app->set(\App\Repository\SettingsRepository::class, new \App\Repository\SettingsRepository($_db_service));
$_settings_repo = $_app->get(\App\Repository\SettingsRepository::class);
$_app->set(\App\Settings\SettingsService::class, new \App\Settings\SettingsService($_settings_repo));
$_app->set(\App\Forms\FieldService::class, new \App\Forms\FieldService($_db_service));
$_app->set(\App\Cache\CacheService::class, new \App\Cache\CacheService());
$_app->set(\App\Repository\AuditRepository::class, new \App\Repository\AuditRepository($_db_service));
$_audit_repo = $_app->get(\App\Repository\AuditRepository::class);
$_app->set(\App\Audit\AuditLogService::class, new \App\Audit\AuditLogService($_audit_repo));
$_app->set(\App\Repository\AdminRepository::class, new \App\Repository\AdminRepository($_db_service, $_settings_repo));
$_app->set(\App\Repository\FormRepository::class, new \App\Repository\FormRepository($_db_service));
$_app->set(\App\Repository\SubmissionRepository::class, new \App\Repository\SubmissionRepository($_db_service));
$_app->set(\App\Repository\TokenRepository::class, new \App\Repository\TokenRepository($_db_service));
$_app->set(\App\Repository\AttachmentRepository::class, new \App\Repository\AttachmentRepository($_db_service));
$_app->set(\App\Render\HtmlService::class, new \App\Render\HtmlService());
$_app->set(\App\Workflow\ConditionEvaluator::class, new \App\Workflow\ConditionEvaluator());
$_settings_svc = $_app->get(\App\Settings\SettingsService::class);
$_mail_svc = new \App\Mail\MailService($_db_service, $_settings_svc);
$_app->set(\App\Mail\MailService::class, $_mail_svc);
$_fields_svc = $_app->get(\App\Forms\FieldService::class);
$_conditions_svc = $_app->get(\App\Workflow\ConditionEvaluator::class);
$_workflow_svc = new \App\Workflow\WorkflowEngine($_db_service, $_settings_svc, $_mail_svc, $_fields_svc, $_conditions_svc);
$_app->set(\App\Workflow\WorkflowEngine::class, $_workflow_svc);
$_html_svc = $_app->get(\App\Render\HtmlService::class);
$_app->set(\App\View\ViewRenderer::class, new \App\View\ViewRenderer($_html_svc));
$_app->set(\App\View\EmailView::class, new \App\View\EmailView());
$_app->set(\App\Persona\PersonaService::class, new \App\Persona\PersonaService($_db_service));
$_app->set(\App\Stats\StatsService::class, new \App\Stats\StatsService($_db_service));
$_app->set(\App\Token\TokenService::class, new \App\Token\TokenService(
    $_db_service,
    $_settings_svc,
    $_app->get(\App\Auth\AuthService::class),
    $_app->get(\App\Audit\AuditLogService::class),
    $_mail_svc,
    $_workflow_svc
));
$_app->set(\App\Forms\ValidatorDataService::class, new \App\Forms\ValidatorDataService($_db_service));
$_attachment_repo = $_app->get(\App\Repository\AttachmentRepository::class);
$_app->set(\App\Attachment\AttachmentService::class, new \App\Attachment\AttachmentService($_attachment_repo));
$_app->set(\App\Cron\CronService::class, new \App\Cron\CronService($_db_service));
$_app->set(\App\Webhook\WebhookService::class, new \App\Webhook\WebhookService($_db_service, $_settings_svc));
$_app->set(\App\Validation\ValidationService::class, new \App\Validation\ValidationService());
$_app->set(\App\Export\ExportService::class, new \App\Export\ExportService($_db_service, $_app->get(\App\Auth\AuthService::class)));
$_app->set(\App\Email\EmailVerificationService::class, new \App\Email\EmailVerificationService($_app->get(\App\Cache\CacheService::class)));
