<?php

declare(strict_types=1);

namespace App\Settings;

use App\Contract\SettingsInterface;
use App\Repository\SettingsRepository;

/**
 * Service de gestion des settings (key/value store).
 */
final class SettingsService implements SettingsInterface
{
    /** @var array<string, string> */
    private static array $cache = [];

    public function __construct(private readonly SettingsRepository $settingsRepository) {}

    public function get(string $key, string $default = ''): string
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $val = $this->settingsRepository->get($key);

            if ($val !== null) {
                self::$cache[$key] = $val;
                return $val;
            }
        } catch (\Throwable) {
            // @silent-ok: fallback — DB not yet ready, use default
        }

        // SETTINGS_DEFAULTS contient des entiers (smtp_port, mail_dry_run) :
        // sans cast, get() déclarée `string` renvoyait un int et levait un
        // TypeError dès que la ligne était absente de la base.
        /** @var array<string, string>|string $defaults */
        $defaults = defined('SETTINGS_DEFAULTS') ? SETTINGS_DEFAULTS : [];
        \assert(\is_array($defaults));
        $result = (string) ($defaults[$key] ?? $default);
        self::$cache[$key] = $result;
        return $result;
    }

    public function set(string $key, string $value, string $updatedBy = ''): void
    {
        $this->settingsRepository->set($key, $value, $updatedBy);

        self::$cache[$key] = $value;
    }

    /**
     * @return list<string>
     */
    public static function getSensitiveKeys(): array
    {
        return ['smtp_pass', 'ldap_bind_pass', 'app_test_secret'];
    }
}
