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

use App\Core\App;
use App\Core\Database;
use App\Core\Config;
use App\Auth\AuthService;
use App\Settings\SettingsService;
use App\Forms\FieldService;
use App\Security\SecurityService;
use App\Mail\MailService;
use App\Audit\AuditLogService;
use App\Cache\CacheService;
use App\Render\HtmlService;
use App\View\ViewRenderer;
use App\View\EmailView;
use App\Stats\StatsService;
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;
use App\Persona\PersonaService;

// Charger la config traditionnelle (définit BASE_URL, DB_PATH, etc.)
require_once __DIR__ . '/../config.php';

$app = App::getInstance();

// Enregistrer les services dans le container
$db = new Database();
$app->set(Database::class, $db);
$app->set(Config::class, new Config());

// Services seront instanciés à la demande
$app->set(AuthService::class, new AuthService($db));
$app->set(SettingsService::class, new SettingsService($db));
$app->set(FieldService::class, new FieldService($db));
$app->set(SecurityService::class, new SecurityService());
$app->set(AuditLogService::class, new AuditLogService($db));
$app->set(CacheService::class, new CacheService());
$app->set(HtmlService::class, new HtmlService());
$app->set(ConditionEvaluator::class, new ConditionEvaluator());
$app->set(StatsService::class, new StatsService($db));
$app->set(PersonaService::class, new PersonaService($db));

// Services avec dépendances
$settings = $app->get(SettingsService::class);
$mail = new MailService($db, $settings);
$app->set(MailService::class, $mail);

$fields = $app->get(FieldService::class);
$conditions = $app->get(ConditionEvaluator::class);
$workflow = new WorkflowEngine($db, $settings, $mail, $fields, $conditions);
$app->set(WorkflowEngine::class, $workflow);

// View renderers — délèguent aux fonctions render_*() existantes
$html = $app->get(HtmlService::class);
$view = new ViewRenderer($html);
$app->set(ViewRenderer::class, $view);
$app->set(EmailView::class, new EmailView());

// Note : les méthodes statiques App::db(), App::config(), App::auth() sont
// définies dans src/Core/App.php. Le bloc `if (!method_exists(App::class, 'auth'))`
// historique a été supprimé en v9.1.1 : il était vide et la méthode existe
// toujours → code mort + erreur PHPStan (function.alreadyNarrowedType).

