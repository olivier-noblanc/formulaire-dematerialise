<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogService;
use App\Auth\AuthService;
use App\Cache\CacheService;
use App\Core\App;
use App\Core\Database;
use App\Render\HtmlService;
use App\Repository\AlertRepository;
use App\Repository\AttachmentRepository;
use App\Repository\AuditRepository;
use App\Repository\FormRepository;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Security\SecurityService;
use App\Settings\SettingsService;

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
    protected AuthService $auth;
    protected SettingsService $settings;
    protected SecurityService $security;
    protected AuditLogService $audit;
    protected CacheService $cache;
    protected HtmlService $html;

    // Repositories
    protected FormRepository $formRepo;
    protected SubmissionRepository $submissionRepo;
    protected TokenRepository $tokenRepo;
    protected AttachmentRepository $attachmentRepo;
    protected SettingsRepository $settingsRepo;
    protected AuditRepository $auditRepo;
    protected AlertRepository $alertRepo;

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
        $this->settings       = $this->app->get(SettingsService::class);
        $this->auth           = $this->app->get(AuthService::class);
        $this->security       = $this->app->get(SecurityService::class);
        $this->audit          = $this->app->get(AuditLogService::class);
        $this->cache          = $this->app->get(CacheService::class);
        $this->html           = $this->app->get(HtmlService::class);

        // Repositories
        $this->formRepo       = $this->app->get(FormRepository::class);
        $this->submissionRepo = $this->app->get(SubmissionRepository::class);
        $this->tokenRepo      = $this->app->get(TokenRepository::class);
        $this->attachmentRepo = $this->app->get(AttachmentRepository::class);
        $this->settingsRepo   = $this->app->get(SettingsRepository::class);
        $this->auditRepo      = $this->app->get(AuditRepository::class);
        $this->alertRepo      = $this->app->get(AlertRepository::class);
    }

    protected function renderPage(string $title, string $currentPage = '', string $pageCss = '', string $content = ''): string
    {
        return new \App\Render\NavigationRenderer()->page($title, $currentPage, $pageCss, $content);
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
