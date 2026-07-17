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

    public function delete(string $key): bool
    {
        return $this->execute('DELETE FROM settings WHERE key = ?', [$key]);
    }

    /**
     * @return array<int, array{key: string, value: string}>
     */
    public function getAll(): array
    {
        /** @var array<int, array{key: string, value: string}> $result */
        $result = $this->fetchAll('SELECT key, value FROM settings ORDER BY key');
        return $result;
    }
}
