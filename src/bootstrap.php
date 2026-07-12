<?php

declare(strict_types=1);

/**
 * Bootstrap OOP — initialise tous les services dans le container DI.
 *
 * Usage:
 *   require_once 'src/bootstrap.php';
 *   $auth = \App\Core\App::auth();
 *   $pdo = \App\Core\App::db()->getPdo();
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Attachment\AttachmentService;
use App\Audit\AuditLogService;
use App\Auth\AuthService;
use App\Cache\CacheService;
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\MigrationService;
use App\Cron\CronService;
use App\Email\EmailVerificationService;
use App\Export\ExportService;
use App\Forms\FieldService;
use App\Forms\ValidatorDataService;
use App\Mail\MailService;
use App\Persona\PersonaService;
use App\Render\HtmlService;
use App\Repository\AdminRepository;
use App\Repository\AttachmentRepository;
use App\Repository\AuditRepository;
use App\Repository\FormRepository;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Rgpd\RgpdService;
use App\Security\SecurityService;
use App\Settings\SettingsService;
use App\Stats\StatsService;
use App\Token\TokenService;
use App\Validation\ValidationService;
use App\View\EmailView;
use App\View\ViewRenderer;
use App\Workflow\ConditionEvaluator;
use App\Workflow\WorkflowEngine;

// Charger la config traditionnelle (définit BASE_URL, DB_PATH, etc.)
require_once __DIR__ . '/../config.php';

$app = App::getInstance();

// Enregistrer les services dans le container
$db = new Database();
$app->set(Database::class, $db);
$app->set(Config::class, new Config());

// Services seront instanciés à la demande
$app->set(AuthService::class, new AuthService($db));
$app->set(SettingsRepository::class, new SettingsRepository($db));
$settingsRepo = $app->get(SettingsRepository::class);
$app->set(SettingsService::class, new SettingsService($settingsRepo));
$app->set(AuditRepository::class, new AuditRepository($db));
$app->set(AdminRepository::class, new AdminRepository($db, $settingsRepo));
$app->set(FormRepository::class, new FormRepository($db));
$app->set(SubmissionRepository::class, new SubmissionRepository($db));
$app->set(TokenRepository::class, new TokenRepository($db));
$app->set(AttachmentRepository::class, new AttachmentRepository($db));
$app->set(AlertRepository::class, new AlertRepository($db));
$app->set(FieldService::class, new FieldService($db));
$app->set(HtmlService::class, new HtmlService());
$app->set(SecurityService::class, new SecurityService($app->get(HtmlService::class)));
$auditRepo = $app->get(AuditRepository::class);
$app->set(AuditLogService::class, new AuditLogService($auditRepo));
$app->set(CacheService::class, new CacheService());
$app->set(ConditionEvaluator::class, new ConditionEvaluator());
$app->set(StatsService::class, new StatsService($db));
$app->set(PersonaService::class, new PersonaService($db));

// Services avec dépendances
$settings = $app->get(SettingsService::class);
$mail = new MailService($db, $settings);
$app->set(MailService::class, $mail);
$app->get(AuthService::class)->setMailer($mail);

$fields = $app->get(FieldService::class);
$conditions = $app->get(ConditionEvaluator::class);
$workflow = new WorkflowEngine($db, $settings, $mail, $fields, $conditions);
$app->set(WorkflowEngine::class, $workflow);

// View renderers — délèguent aux fonctions render_*() existantes
$html = $app->get(HtmlService::class);
$view = new ViewRenderer($html);
$app->set(ViewRenderer::class, $view);
$app->set(EmailView::class, new EmailView());

// Token lifecycle service
$tokenService = new TokenService($db, $settings, $app->get(AuthService::class), $app->get(AuditLogService::class), $mail, $workflow);
$app->set(TokenService::class, $tokenService);

// Attachment service
$attachmentRepo = $app->get(AttachmentRepository::class);
$app->set(AttachmentService::class, new AttachmentService($attachmentRepo));

// Validator data service
$app->set(ValidatorDataService::class, new ValidatorDataService($db, $fields));

// Cron service
$app->set(CronService::class, new CronService($db));

// Migration service
$app->set(MigrationService::class, new MigrationService($db));

// Validation service
$app->set(ValidationService::class, new ValidationService());

// Export service
$app->set(ExportService::class, new ExportService($db, $app->get(AuthService::class)));

// Email verification service
$app->set(EmailVerificationService::class, new EmailVerificationService($app->get(CacheService::class)));

// RGPD service
$app->set(RgpdService::class, new RgpdService($db));

// Note : les méthodes statiques App::db(), App::config(), App::auth() sont
// définies dans src/Core/App.php. Le bloc `if (!method_exists(App::class, 'auth'))`
// historique a été supprimé en v9.1.1 : il était vide et la méthode existe
// toujours → code mort + erreur PHPStan (function.alreadyNarrowedType).
