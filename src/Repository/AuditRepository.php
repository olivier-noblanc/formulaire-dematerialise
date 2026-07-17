<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\App;

/**
 * Repository pour les journaux d'audit et de sécurité.
 *
 * Étend BaseRepository pour fournir des opérations CRUD
 * sur les tables audit_log et security_log.
 */
final class AuditRepository extends BaseRepository
{
    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): bool
    {
        if ($actor === '') {
            $actor = App::auth()->getUser() ?: '';
        }
        return $this->execute(
            "INSERT INTO audit_log (id, action, target, detail, actor, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [\generate_uuid(), $action, $target, $detail, $actor]
        );
    }

    public function securityLog(string $event, string $detail = '', string $actor = ''): bool
    {
        if ($actor === '') {
            $actor = App::auth()->getUser() ?: '';
        }
        return $this->execute(
            "INSERT INTO audit_log (id, action, target, detail, actor, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [\generate_uuid(), 'security_event', 'security:' . $event, $detail, $actor]
        );
    }

    /**
     * @return array<int, array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}>
     */
    public function getLogs(int $limit = 100, string $actionFilter = ''): array
    {
        $sql = 'SELECT id, action, target, detail, actor, ip, created_at FROM audit_log';
        $params = [];
        if ($actionFilter !== '') {
            $sql .= ' WHERE action = ?';
            $params[] = $actionFilter;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = $limit;
        /** @var array<int, array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}> $result */
        $result = $this->fetchAll($sql, $params);
        return $result;
    }

    /**
     * @return array<int, array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}>
     */
    public function getSecurityLogs(int $limit = 100): array
    {
        /** @var array<int, array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, action, target, detail, actor, ip, created_at FROM audit_log WHERE action = ? ORDER BY created_at DESC LIMIT ?',
            ['security_event', $limit]
        );
        return $result;
    }

    public function getLastBackupDate(): ?string
    {
        /** @var array{created_at: string}|null $result */
        $result = $this->fetchOne(
            "SELECT created_at FROM audit_log
             WHERE action IN ('backup_download', 'backup_restore')
             ORDER BY created_at DESC LIMIT 1"
        );
        return $result !== null ? (string) $result['created_at'] : null;
    }

    public function countAll(): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne('SELECT COUNT(*) as cnt FROM audit_log');
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @param array<string, string> $filters
     * @return array<int, array{created_at: string, action: string, actor: string, target: string|null, detail: string|null, ip: string|null}>
     */
    public function findFiltered(array $filters): array
    {
        [$whereSql, $params] = $this->buildFilterWhere($filters);
        /** @var array<int, array{created_at: string, action: string, actor: string, target: string|null, detail: string|null, ip: string|null}> $result */
        $result = $this->fetchAll(
            "SELECT created_at, action, actor, target, detail, ip FROM audit_log $whereSql ORDER BY created_at DESC",
            $params
        );
        return $result;
    }

    /**
     * @param array<string, string> $filters
     */
    public function countFiltered(array $filters): int
    {
        [$whereSql, $params] = $this->buildFilterWhere($filters);
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne("SELECT COUNT(*) as cnt FROM audit_log $whereSql", $params);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * @param array<string, string> $filters
     * @return array<int, array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}>
     */
    public function findFilteredPaginated(array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildFilterWhere($filters);
        /** @var array<int, array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}> $result */
        $result = $this->fetchAll(
            "SELECT id, action, target, detail, actor, ip, created_at FROM audit_log $whereSql ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function getDistinctActionTypes(): array
    {
        $stmt = $this->pdo()->query('SELECT DISTINCT action FROM audit_log ORDER BY action');
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildFilterWhere(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['log_action'] ?? '') !== '') {
            $where[] = 'action = ?';
            $params[] = $filters['log_action'];
        }
        if (($filters['log_actor'] ?? '') !== '') {
            $where[] = 'actor LIKE ?';
            $params[] = '%' . $filters['log_actor'] . '%';
        }
        if (($filters['log_target'] ?? '') !== '') {
            $where[] = 'target LIKE ?';
            $params[] = '%' . $filters['log_target'] . '%';
        }
        if (($filters['log_date_debut'] ?? '') !== '') {
            $where[] = 'date(created_at) >= ?';
            $params[] = $filters['log_date_debut'];
        }
        if (($filters['log_date_fin'] ?? '') !== '') {
            $where[] = 'date(created_at) <= ?';
            $params[] = $filters['log_date_fin'];
        }
        $sql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        return [$sql, $params];
    }
}
