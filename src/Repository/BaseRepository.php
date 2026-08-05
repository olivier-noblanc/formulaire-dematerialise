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

    public function testConnection(): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1');
        $stmt->execute();
        $val = $stmt->fetchColumn();

        return $val !== null && $val !== false && (int) $val === 1;
    }

    /**
     * Démarre une transaction. Les repositories partagent la même connexion PDO
     * (Database singleton), donc une transaction ouverte sur un repo est visible
     * par tous les autres repos — permet à un service d'orchestrer une transaction
     * multi-tables en appelant beginTransaction/commit/rollBack sur un seul repo.
     */
    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollBack(): void
    {
        $this->pdo()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
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
