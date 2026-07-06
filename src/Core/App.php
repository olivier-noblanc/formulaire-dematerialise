<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Container d'injection de dépendances minimaliste.
 * Singleton — accès global via App::getInstance().
 *
 * Les services sont enregistrés EXTERNEMENT (par helpers.php ou bootstrap.php)
 * car leur instanciation dépend de config.php (DB_PATH, etc.).
 */
final class App
{
    private static ?App $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct()
    {
        // Ne RIEN instancier ici — les services dépendent de config.php
        // qui est chargé APRÈS le chargement de l'autoload.
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Récupère un service enregistré.
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        if (!isset($this->services[$class])) {
            throw new \RuntimeException("Service non enregistré: $class");
        }
        return $this->services[$class];
    }

    public function set(string $class, object $service): void
    {
        $this->services[$class] = $service;
    }

    public static function db(): Database
    {
        return self::getInstance()->get(Database::class);
    }

    public static function config(): Config
    {
        return self::getInstance()->get(Config::class);
    }

    public static function auth(): \App\Auth\AuthService
    {
        return self::getInstance()->get(\App\Auth\AuthService::class);
    }

    public static function settings(): \App\Settings\SettingsService
    {
        return self::getInstance()->get(\App\Settings\SettingsService::class);
    }

    public static function fields(): \App\Forms\FieldService
    {
        return self::getInstance()->get(\App\Forms\FieldService::class);
    }

    public static function security(): \App\Security\SecurityService
    {
        return self::getInstance()->get(\App\Security\SecurityService::class);
    }

    public static function mail(): \App\Mail\MailService
    {
        return self::getInstance()->get(\App\Mail\MailService::class);
    }

    public static function audit(): \App\Audit\AuditLogService
    {
        return self::getInstance()->get(\App\Audit\AuditLogService::class);
    }

    public static function cache(): \App\Cache\CacheService
    {
        return self::getInstance()->get(\App\Cache\CacheService::class);
    }

    public static function html(): \App\Render\HtmlService
    {
        return self::getInstance()->get(\App\Render\HtmlService::class);
    }

    public static function workflow(): \App\Workflow\WorkflowEngine
    {
        return self::getInstance()->get(\App\Workflow\WorkflowEngine::class);
    }

    public static function conditions(): \App\Workflow\ConditionEvaluator
    {
        return self::getInstance()->get(\App\Workflow\ConditionEvaluator::class);
    }

    public static function view(): \App\View\ViewRenderer
    {
        return self::getInstance()->get(\App\View\ViewRenderer::class);
    }
}
