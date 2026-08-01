<?php
/**
 * phpunit_bootstrap.php — Bootstrap for PHPUnit tests.
 *
 * Loads the application in TEST_MODE so services can be tested
 * without hitting a real SMTP server or requiring CSRF tokens.
 */

// Set test mode headers BEFORE any application code loads
$_SERVER['HTTP_X_TEST_MODE'] = '1';
$_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
$_SERVER['AUTH_USER'] = 'DREETS\\testeur';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = '';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Load the full application stack
require_once dirname(__DIR__) . '/helpers.php';

// Register services in the App container for tests
use App\Core\App;
use App\Core\Database;
use App\Auth\AuthService;
use App\Settings\SettingsService;
use App\Security\SecurityService;
use App\Cache\CacheService;
use App\Render\HtmlService;
use App\Audit\AuditLogService;
use App\Mail\MailService;
use App\Forms\FieldService;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Persona\PersonaService;
use App\Stats\StatsService;
use App\Token\TokenService;
use App\Attachment\AttachmentService;
use App\Cron\CronService;
use App\Forms\ValidatorDataService;
use App\Validation\ValidationService;
use App\Email\EmailVerificationService;
use App\Export\ExportService;
use App\Docs\DocumentationService;
use App\Repository\SettingsRepository;
use App\Repository\AuditRepository;
use App\Repository\AdminRepository;
use App\Repository\FormRepository;
use App\Repository\AttachmentRepository;
use App\Repository\AlertRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Repository\MailRepository;
use App\Repository\DelegationRepository;
use App\Repository\PersonaTokenRepository;
use App\Repository\LazyCronRepository;

$app = App::getInstance();

// Register services (idempotent — set() overwrites existing)
$db = new Database();
    $app->set(Database::class, $db);
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
    $app->set(DelegationRepository::class, new DelegationRepository($db));
    $app->set(PersonaTokenRepository::class, new PersonaTokenRepository($db));
    $app->set(LazyCronRepository::class, new LazyCronRepository($db));
    $app->set(AuthService::class, new AuthService($db));
    $app->set(CacheService::class, new CacheService());
    $app->set(HtmlService::class, new HtmlService());
    $app->set(\App\Render\DynamicCssService::class, new \App\Render\DynamicCssService());
    $app->set(SecurityService::class, new SecurityService($app->get(HtmlService::class)));
    $app->set(AuditLogService::class, new AuditLogService($app->get(AuditRepository::class)));
    $app->set(MailRepository::class, new MailRepository($db));
    $app->set(MailService::class, new MailService($app->get(MailRepository::class), $app->get(SettingsService::class)));
    $app->set(FieldService::class, new FieldService());
    $app->set(ConditionEvaluator::class, new ConditionEvaluator());
    $app->set(PersonaService::class, new PersonaService($db));
    $app->set(StatsService::class, new StatsService());
$app->set(WorkflowEngine::class, new WorkflowEngine(
    $app->get(SettingsService::class),
    $app->get(MailService::class),
    $app->get(FieldService::class),
    $app->get(ConditionEvaluator::class),
    $app->get(SubmissionRepository::class),
    $app->get(TokenRepository::class),
    $app->get(FormRepository::class)
));
$app->set(TokenService::class, new TokenService(
    $app->get(SettingsService::class),
    $app->get(AuthService::class),
    $app->get(AuditLogService::class),
    $app->get(MailService::class),
    $app->get(SubmissionRepository::class),
    $app->get(TokenRepository::class),
    $app->get(DelegationRepository::class)
));
$app->set(ValidatorDataService::class, new ValidatorDataService($app->get(SubmissionRepository::class), $app->get(FormRepository::class), $app->get(FieldService::class)));
$app->set(AttachmentService::class, new AttachmentService($app->get(AttachmentRepository::class)));
$app->set(CronService::class, new CronService($db, $app->get(LazyCronRepository::class)));
$app->set(ValidationService::class, new ValidationService());
$app->set(ExportService::class, new ExportService($app->get(AuthService::class), $app->get(SubmissionRepository::class)));
$app->set(EmailVerificationService::class, new EmailVerificationService($app->get(CacheService::class)));
$app->set(DocumentationService::class, new DocumentationService());
$app->set(\App\Rgpd\RgpdService::class, new \App\Rgpd\RgpdService($app->get(SubmissionRepository::class), $app->get(TokenRepository::class), $app->get(AttachmentRepository::class), $app->get(AlertRepository::class), $app->get(AdminRepository::class), $app->get(DelegationRepository::class)));

// Seed testeur@e2e.test in admins (s'assure qu'il existe même si la
// migration v28 a été exécutée avant l'ajout de ce seed dans son code).
$stmt = $db->getPdo()->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, 'testeur@e2e.test', datetime('now'))");
$stmt->execute([\generate_uuid()]);
