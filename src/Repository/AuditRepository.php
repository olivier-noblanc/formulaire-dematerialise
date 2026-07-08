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

    /** @return array<int, array<string, mixed>> */
    public function getLogs(int $limit = 100, string $actionFilter = ''): array
    {
        $sql = "SELECT * FROM audit_log";
        $params = [];
        if ($actionFilter !== '') {
            $sql .= " WHERE action = ?";
            $params[] = $actionFilter;
        }
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        return $this->fetchAll($sql, $params);
    }

    /** @return array<int, array<string, mixed>> */
    public function getSecurityLogs(int $limit = 100): array
    {
        return $this->fetchAll(
            "SELECT * FROM audit_log WHERE action = ? ORDER BY created_at DESC LIMIT ?",
            ['security_event', $limit]
        );
    }
}