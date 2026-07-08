<?php
declare(strict_types=1);

namespace App\Audit;

use App\Contract\AuditInterface;
use App\Core\App;
use App\Core\Database;
use App\Repository\AuditRepository;

/**
 * Service de journalisation d'audit et sécurité.
 */
final class AuditLogService implements AuditInterface
{
    private AuditRepository $repo;

    public function __construct(AuditRepository $repo)
    {
        $this->repo = $repo;
    }

    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): void
    {
        if (empty($actor)) {
            $actor = App::auth()->getUser() ?: 'system';
        }

        // Masquer les emails sauf en CLI ou actions critiques
        if (php_sapi_name() !== 'cli' && !in_array($action, ['security_event', 'admin_request', 'form_import'], true)) {
            $detail = preg_replace('/[\w.\-]+@[\w.\-]+\.\w+/', '[email]', $detail) ?? $detail;
        }

        try {
            $this->repo->log($action, $target, $detail, $actor);
        } catch (\Throwable $e) {
            error_log('AuditLog error: ' . $e->getMessage());
        }
    }

    public function securityLog(string $event, string $detail = '', string $actor = ''): void
    {
        if (empty($actor)) {
            $actor = App::auth()->getUser() ?: 'system';
        }
        error_log('[SECURITY] ' . $event . ': ' . $detail . ' (actor: ' . $actor . ')');
        $this->log('security_event', 'security:' . $event, $detail, $actor);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLogs(int $limit = 100, string $actionFilter = ''): array
    {
        return $this->repo->getLogs($limit, $actionFilter);
    }
}
