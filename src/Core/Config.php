<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuration de l'application.
 * Lit config.php (déjà chargé par core_bootstrap.php).
 */
final class Config
{
    /** @return array<string, int|string> */
    public function getDefaults(): array
    {
        return defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS : [];
    }

    public function get(string $key, string $default = ''): string
    {
        return (string) ($this->getDefaults()[$key] ?? $default);
    }

    public function getBaseUrl(): string
    {
        return defined('BASE_URL') ? BASE_URL : '';
    }

    public function getDbPath(): string
    {
        return defined('DB_PATH') ? DB_PATH : '';
    }

    public function isTestMode(): bool
    {
        if (!defined('TEST_MODE')) {
            return false;
        }
        return (bool) constant('TEST_MODE');
    }

    public function getAppName(): string
    {
        $defaults = $this->getDefaults();
        return (string) ($defaults['app_name'] ?? 'CircuitDémat');
    }
}
