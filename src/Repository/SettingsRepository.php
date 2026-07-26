<?php

declare(strict_types=1);

namespace App\Repository;

final class SettingsRepository extends BaseRepository
{
    public function get(string $key, string $default = ''): ?string
    {
        $result = $this->fetchOne(
            'SELECT value FROM settings WHERE key = ?',
            [$key]
        );
        return $result !== null ? ($result['value'] ?? $default) : null;
    }

    public function set(string $key, string $value, string $updatedBy = ''): bool
    {
        return $this->execute(
            "INSERT OR REPLACE INTO settings (key, value, updated_at, updated_by) VALUES (?, ?, datetime('now'), ?)",
            [$key, $value, $updatedBy]
        );
    }

}
