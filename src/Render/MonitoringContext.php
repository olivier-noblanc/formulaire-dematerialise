<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Context object for MonitoringRenderer.
 *
 * Source of truth for all data consumed by MonitoringRenderer::content(),
 * stats(), and auditLog(). Types are STRICT and match the repository return
 * types exactly — no string-casting, no coercion, no lossy conversion.
 *
 * If a type here doesn't match what the controller passes, it's a bug in
 * the controller (or in the repository), NOT in this DTO.
 */
final readonly class MonitoringContext
{
    /**
     * @param float $taux_validation Percentage 0-100, rounded to 1 decimal (e.g. 67.5). Source: StatsService::getGlobalStats()['taux_validation']
     * @param float $avg_days Average processing time in days (rounded to 1 decimal)
     * @param float $avg_hours Average processing time in hours (rounded to 1 decimal)
     * @param int $bloque_hours Threshold in hours for "blocked" tokens (fixed 96h; relance configured per-form)
     * @param list<array{id: string, email: string, sent_at: string|null, relance_count: int, expires_at: string|null, step_label: string, ordre: int, submission_id: string, submitted_by: string|null, submitted_at: string|null, form_label: string}> $tokens_bloques Source: TokenRepository::findBlocked()
     * @param list<array{submission_id: string, form_label: string, nom_agent: string, deadline: string, deadline_formatted: string, days_remaining: int, pending_steps: int, submitted_by: string}> $active_alerts Built by MonitoringController from findActiveWithDeadlineField()
     * @param list<array{id: string, rule_id: string, submission_id: string, sent_at: string, message: string|null, form_label: string, rule_label: string|null}> $recent_alerts Source: AlertRepository::getLogsWithForm()
     * @param list<array{label: string, total: int, en_cours: int, valide: int, refuse: int}> $by_form_stats Source: FormRepository::getSubmissionCounts()
     * @param list<array{day: string, cnt: int}> $daily_stats Source: SubmissionRepository::getDailyCounts()
     * @param string $smtp_status 'inconnu' | 'ok' | 'erreur'
     * @param string $smtp_detail Human-readable detail message
     * @param string $smtp_debug_log SMTP conversation log (only if test_smtp=1)
     * @param list<array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}> $mail_logs Source: MailRepository::getRecentLogs()
     * @param string $last_remind ISO datetime of last remind.php run (empty if never)
     * @param string $last_alert_check ISO datetime of last alert_check.php run (empty if never)
     * @param array<string, string> $audit_filters Filter values: log_action, log_actor, log_target, log_date_debut, log_date_fin
     * @param int $audit_total Total matching audit log entries (before pagination)
     * @param int $audit_total_pages Total pages (ceil(audit_total / audit_per_page))
     * @param int $audit_page Current page (1-indexed)
     * @param list<array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}> $audit_logs Source: AuditRepository::findFilteredPaginated()
     * @param list<string> $action_types Distinct action types from AuditRepository::getDistinctActionTypes()
     * @param string $audit_base_url Base URL for audit pagination links (includes filters as query string)
     * @param string $audit_base_qs Raw query string of active filters (for hidden inputs)
     */
    public function __construct(
        public int $total_sub,
        public int $valide_sub,
        public int $en_cours_sub,
        public int $refuse_sub,
        public float $taux_validation,
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
    ) {}

    /**
     * Build from legacy array context (BC for lib_wrappers).
     *
     * @param array<string, mixed> $ctx
     */
    public static function fromLegacyArray(array $ctx): self
    {
        return new self(
            total_sub: (int) ($ctx['total_sub'] ?? 0),
            valide_sub: (int) ($ctx['valide_sub'] ?? 0),
            en_cours_sub: (int) ($ctx['en_cours_sub'] ?? 0),
            refuse_sub: (int) ($ctx['refuse_sub'] ?? 0),
            taux_validation: (float) ($ctx['taux_validation'] ?? 0.0),
            avg_days: (float) ($ctx['avg_days'] ?? 0),
            avg_hours: (float) ($ctx['avg_hours'] ?? 0),
            bloque_hours: (int) ($ctx['bloque_hours'] ?? 0),
            tokens_bloques: $ctx['tokens_bloques'] ?? [],
            active_alerts: $ctx['active_alerts'] ?? [],
            recent_alerts: $ctx['recent_alerts'] ?? [],
            by_form_stats: $ctx['by_form_stats'] ?? [],
            daily_stats: $ctx['daily_stats'] ?? [],
            smtp_status: (string) ($ctx['smtp_status'] ?? 'inconnu'),
            smtp_detail: (string) ($ctx['smtp_detail'] ?? ''),
            smtp_debug_log: (string) ($ctx['smtp_debug_log'] ?? ''),
            mail_logs: $ctx['mail_logs'] ?? [],
            last_remind: (string) ($ctx['last_remind'] ?? ''),
            last_alert_check: (string) ($ctx['last_alert_check'] ?? ''),
            audit_filters: $ctx['audit_filters'] ?? [],
            audit_total: (int) ($ctx['audit_total'] ?? 0),
            audit_total_pages: (int) ($ctx['audit_total_pages'] ?? 1),
            audit_page: (int) ($ctx['audit_page'] ?? 1),
            audit_logs: $ctx['audit_logs'] ?? [],
            action_types: $ctx['action_types'] ?? [],
            audit_base_url: (string) ($ctx['audit_base_url'] ?? 'index.php?p=monitoring'),
            audit_base_qs: (string) ($ctx['audit_base_qs'] ?? ''),
        );
    }
}
