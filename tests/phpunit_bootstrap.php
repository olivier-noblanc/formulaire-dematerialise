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
use App\Core\Config;
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
use App\View\ViewRenderer;
use App\Token\TokenService;
use App\Attachment\AttachmentService;
use App\Cron\CronService;
use App\Forms\ValidatorDataService;

$app = App::getInstance();

// Register services (idempotent — set() overwrites existing)
$db = new Database();
    $app->set(Database::class, $db);
    $app->set(Config::class, new Config());
    $app->set(SettingsService::class, new SettingsService($db));
    $app->set(AuthService::class, new AuthService($db));
    $app->set(SecurityService::class, new SecurityService());
    $app->set(CacheService::class, new CacheService());
    $app->set(HtmlService::class, new HtmlService());
    $app->set(AuditLogService::class, new AuditLogService($db));
    $app->set(MailService::class, new MailService($db, $app->get(SettingsService::class)));
    $app->set(FieldService::class, new FieldService($db));
    $app->set(ConditionEvaluator::class, new ConditionEvaluator());
    $app->set(PersonaService::class, new PersonaService($db));
    $app->set(StatsService::class, new StatsService($db));
    $app->set(ViewRenderer::class, new ViewRenderer($app->get(HtmlService::class)));
$app->set(WorkflowEngine::class, new WorkflowEngine(
    $db,
    $app->get(SettingsService::class),
    $app->get(MailService::class),
    $app->get(FieldService::class),
    $app->get(ConditionEvaluator::class)
));
$app->set(TokenService::class, new TokenService(
    $db,
    $app->get(SettingsService::class),
    $app->get(AuthService::class),
    $app->get(AuditLogService::class),
    $app->get(MailService::class),
    $app->get(WorkflowEngine::class)
));
$app->set(ValidatorDataService::class, new ValidatorDataService($db));
$app->set(AttachmentService::class, new AttachmentService($db));
$app->set(CronService::class, new CronService($db));
