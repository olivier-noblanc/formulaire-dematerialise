<?php

declare(strict_types=1);

namespace App\Audit;

use App\Contract\AuditInterface;
use App\Core\App;
use App\Repository\AuditRepository;

/**
 * Service de journalisation d'audit et sécurité.
 */
final readonly class AuditLogService implements AuditInterface
{
    public function __construct(private AuditRepository $auditRepository)
    {
    }

    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): void
    {
        if ($actor === '' || $actor === '0') {
            $actor = App::auth()->getUser() ?: 'system';
        }

        // Masquer les emails sauf en CLI ou actions critiques
        if (php_sapi_name() !== 'cli' && !in_array($action, ['security_event', 'admin_request', 'form_import'], true)) {
            $detail = preg_replace('/[\w.\-]+@[\w.\-]+\.\w+/', '[email]', $detail) ?? $detail;
        }

        try {
            $this->auditRepository->log($action, $target, $detail, $actor);
        } catch (\Throwable $e) {
            error_log('AuditLog error: ' . $e->getMessage() . ' [' . $action . '] ' . $e->getTraceAsString());
        }
    }

    public function securityLog(string $event, string $detail = '', string $actor = ''): void
    {
        if ($actor === '' || $actor === '0') {
            $actor = App::auth()->getUser() ?: 'system';
        }
        error_log('[SECURITY] ' . $event . ': ' . $detail . ' (actor: ' . $actor . ')');
        $this->log('security_event', 'security:' . $event, $detail, $actor);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLogs(int $limit = 100, string $actionFilter = ''): array
    {
        return $this->auditRepository->getLogs($limit, $actionFilter);
    }
}
