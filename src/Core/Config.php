<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuration de l'application.
 * Lit config.php (déjà chargé par core_bootstrap.php).
 */
final class Config
{
    /** @return array<string, string> */
    public function getDefaults(): array
    {
        return defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS : [];
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->getDefaults()[$key] ?? $default;
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
        return defined('TEST_MODE') && TEST_MODE;
    }

    public function getAppName(): string
    {
        return defined('SETTINGS_DEFAULTS') && isset(SETTINGS_DEFAULTS['app_name'])
            ? SETTINGS_DEFAULTS['app_name']
            : 'CircuitDémat';
    }
}
