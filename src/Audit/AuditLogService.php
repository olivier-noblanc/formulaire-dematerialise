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
    public function __construct(private AuditRepository $auditRepository) {}

    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): void
    {
        if ($actor === '' || $actor === '0') {
            $user = App::auth()->getUser();
            $actor = $user !== '' ? $user : 'system';
        }

        // Masquer les emails sauf en CLI ou actions critiques
        if (php_sapi_name() !== 'cli' && !in_array($action, ['security_event', 'admin_request', 'form_import'], true)) {
            $detail = preg_replace('/[\w.\-]+@[\w.\-]+\.\w+/', '[email]', $detail) ?? $detail;
        }

        // Audit log est un chemin critique (RGPD, traçabilité).
        // Règle #9 AGENTS.md : ne jamais avaler une exception sur un chemin critique.
        // Si l'écriture échoue (DB locked, disque plein, schéma drift), l'audit trail
        // est vide sans signal → on relance l'exception pour que l'appelant décide.
        // (audit CTO C-03 2026-08-01)
        $this->auditRepository->log($action, $target, $detail, $actor);
    }

    public function securityLog(string $event, string $detail = '', string $actor = ''): void
    {
        if ($actor === '' || $actor === '0') {
            $user = App::auth()->getUser();
            $actor = $user !== '' ? $user : 'system';
        }
        error_log('[SECURITY] ' . $event . ': ' . $detail . ' (actor: ' . $actor . ')');
        $this->log('security_event', 'security:' . $event, $detail, $actor);
    }


}
