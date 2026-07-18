<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

abstract class BaseRepository
{
    public function __construct(protected Database $db) {}

    public function pdo(): PDO
    {
        return $this->db->getPdo();
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Failed to prepare SQL: $sql");
        }
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Failed to prepare SQL: $sql");
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int, mixed> $params */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo()->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Failed to prepare SQL: $sql");
        }
        return $stmt->execute($params);
    }

    public function lastInsertId(): string
    {
        $id = $this->pdo()->lastInsertId();
        if ($id === false) {
            throw new \RuntimeException('Failed to get last insert ID');
        }
        return $id;
    }

    public function testConnection(): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1');
        $stmt->execute();
        return $stmt->fetchColumn() == '1';
    }

    /**
     * @return array<int, string>
     */
    public function getTableNames(): array
    {
        $stmt = $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
