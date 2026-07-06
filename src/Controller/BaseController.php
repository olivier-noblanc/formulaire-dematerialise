<?php
declare(strict_types=1);

namespace App\Controller;

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
use App\Workflow\WorkflowEngine;
use App\Workflow\ConditionEvaluator;

/**
 * Contrôleur de base — fournit l'accès aux services via DI.
 */
abstract class BaseController
{
    protected Database $db;
    protected Config $config;
    protected AuthService $auth;
    protected SettingsService $settings;
    protected FieldService $fields;
    protected SecurityService $security;
    protected MailService $mail;
    protected AuditLogService $audit;
    protected CacheService $cache;
    protected HtmlService $html;
    protected WorkflowEngine $workflow;
    protected ConditionEvaluator $conditions;

    public function __construct()
    {
        $app = App::getInstance();
        $this->db = $app->get(Database::class);
        $this->config = $app->get(Config::class);

        // Initialiser les services à la demande
        if (!isset($app)) {
            $app = App::getInstance();
        }
    }

    protected function initServices(): void
    {
        $app = App::getInstance();
        $this->settings = $app->get(SettingsService::class);
        $this->auth = $app->get(AuthService::class);
        $this->fields = $app->get(FieldService::class);
        $this->security = $app->get(SecurityService::class);
        $this->mail = $app->get(MailService::class);
        $this->audit = $app->get(AuditLogService::class);
        $this->cache = $app->get(CacheService::class);
        $this->html = $app->get(HtmlService::class);
        $this->conditions = $app->get(ConditionEvaluator::class);
        $this->workflow = $app->get(WorkflowEngine::class);
    }

    protected function renderPage(string $title, string $currentPage = '', string $pageCss = '', string $content = ''): string
    {
        return render_page($title, $currentPage, $pageCss, $content);
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    protected function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
