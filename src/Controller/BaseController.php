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
use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Repository\AttachmentRepository;
use App\Repository\AdminRepository;
use App\Repository\SettingsRepository;
use App\Repository\AuditRepository;

/**
 * Contrôleur de base — fournit l'accès aux services et repositories via DI.
 *
 * Le container App est injecté via le constructeur (constructor injection).
 * Par défaut, le singleton App::getInstance() est utilisé pour la compatibilité
 * ascendante. Passer explicitement une instance App permet l'injection de
 * dépendances (tests unitaires, contexts alternatifs).
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

    // Repositories
    protected FormRepository $formRepo;
    protected SubmissionRepository $submissionRepo;
    protected TokenRepository $tokenRepo;
    protected AttachmentRepository $attachmentRepo;
    protected AdminRepository $adminRepo;
    protected SettingsRepository $settingsRepo;
    protected AuditRepository $auditRepo;

    protected App $app;

    /**
     * @param App|null $app Container DI injecté — null par défaut pour la
     *                      compatibilité avec les contrôleurs enfants existants.
     *                      Passer une instance App permet l'injection explicite
     *                      (tests, contexts alternatifs).
     */
    public function __construct(?App $app = null)
    {
        $this->app = $app ?? App::getInstance();

        $this->db             = $this->app->get(Database::class);
        $this->config         = $this->app->get(Config::class);
        $this->settings       = $this->app->get(SettingsService::class);
        $this->auth           = $this->app->get(AuthService::class);
        $this->fields         = $this->app->get(FieldService::class);
        $this->security       = $this->app->get(SecurityService::class);
        $this->mail           = $this->app->get(MailService::class);
        $this->audit          = $this->app->get(AuditLogService::class);
        $this->cache          = $this->app->get(CacheService::class);
        $this->html           = $this->app->get(HtmlService::class);
        $this->conditions     = $this->app->get(ConditionEvaluator::class);
        $this->workflow       = $this->app->get(WorkflowEngine::class);

        // Repositories
        $this->formRepo       = $this->app->get(FormRepository::class);
        $this->submissionRepo = $this->app->get(SubmissionRepository::class);
        $this->tokenRepo      = $this->app->get(TokenRepository::class);
        $this->attachmentRepo = $this->app->get(AttachmentRepository::class);
        $this->adminRepo      = $this->app->get(AdminRepository::class);
        $this->settingsRepo   = $this->app->get(SettingsRepository::class);
        $this->auditRepo      = $this->app->get(AuditRepository::class);
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
