<?php
declare(strict_types=1);

namespace App\Audit;

use App\Contract\AuditInterface;
use App\Core\Database;

/**
 * Service de journalisation d'audit et sécurité.
 */
final class AuditLogService implements AuditInterface
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): void
    {
        if (empty($actor)) {
            $actor = function_exists('get_auth_user') ? get_auth_user() : 'system';
        }

        // Masquer les emails sauf en CLI ou actions critiques
        if (php_sapi_name() !== 'cli' && !in_array($action, ['security_event', 'admin_request', 'form_import'], true)) {
            $detail = preg_replace('/[\w.\-]+@[\w.\-]+\.\w+/', '[email]', $detail) ?? $detail;
        }

        try {
            $pdo = $this->db->getPdo();
            $pdo->prepare("INSERT INTO audit_log (id, action, target, detail, actor, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))")
                ->execute([
                    $this->generateUuid(),
                    $action, $target, $detail, $actor,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
        } catch (\Throwable $e) {
            error_log('AuditLog error: ' . $e->getMessage());
        }
    }

    public function securityLog(string $event, string $detail = '', string $actor = ''): void
    {
        if (empty($actor)) {
            $actor = function_exists('get_auth_user') ? get_auth_user() : 'system';
        }
        error_log('[SECURITY] ' . $event . ': ' . $detail . ' (actor: ' . $actor . ')');
        $this->log('security_event', 'security:' . $event, $detail, $actor);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLogs(int $limit = 100, string $actionFilter = ''): array
    {
        $pdo = $this->db->getPdo();
        if (!empty($actionFilter)) {
            $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE action = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$actionFilter, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function generateUuid(): string
    {
        return \generate_uuid();
    }
}
