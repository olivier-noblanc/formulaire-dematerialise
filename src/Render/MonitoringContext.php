<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Context object for MonitoringRenderer.
 *
 * Replaces the loose array<string, mixed> $ctx parameter
 * used by content(), stats(), and auditLog().
 */
final readonly class MonitoringContext
{
    /**
     * @param int                     $total_sub
     * @param int                     $valide_sub
     * @param int                     $en_cours_sub
     * @param int                     $refuse_sub
     * @param string                  $taux_validation
     * @param float                   $avg_days
     * @param float                   $avg_hours
     * @param int                     $bloque_hours
     * @param list<array>             $tokens_bloques
     * @param list<array>             $active_alerts
     * @param list<array>             $recent_alerts
     * @param list<array>             $by_form_stats
     * @param list<array>             $daily_stats
     * @param string                  $smtp_status
     * @param string                  $smtp_detail
     * @param string                  $smtp_debug_log
     * @param list<array>             $mail_logs
     * @param string                  $last_remind
     * @param string                  $last_alert_check
     * @param array<string, string>   $audit_filters
     * @param int                     $audit_total
     * @param int                     $audit_total_pages
     * @param int                     $audit_page
     * @param list<array>             $audit_logs
     * @param list<string>            $action_types
     * @param string                  $audit_base_url
     * @param string                  $audit_base_qs
     */
    public function __construct(
        public int $total_sub,
        public int $valide_sub,
        public int $en_cours_sub,
        public int $refuse_sub,
        public string $taux_validation,
        public float $avg_days,
        public float $avg_hours,
        public int $bloque_hours,
        public array $tokens_bloques,
        public array $active_alerts,
        public array $recent_alerts,
        public array $by_form_stats,
        public array $daily_stats,
        public string $smtp_status,
        public string $smtp_detail,
        public string $smtp_debug_log,
        public array $mail_logs,
        public string $last_remind,
        public string $last_alert_check,
        public array $audit_filters,
        public int $audit_total,
        public int $audit_total_pages,
        public int $audit_page,
        public array $audit_logs,
        public array $action_types,
        public string $audit_base_url,
        public string $audit_base_qs,
    ) {
    }
}
