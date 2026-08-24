<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Rendu de la page de surveillance / monitoring (monitoring.php).
 *
 * Chaque fonction render_monitoring_*() historique devient une méthode statique.
 * Les wrappers globaux dans lib/render_monitoring.php assurent la rétrocompatibilité.
 *
 * Les templates HTML sont stockés dans src/Render/templates/monitoring_*.php
 * et chargés via loadTemplate().
 */
final class MonitoringRenderer
{
    private static ?string $templatesDir = null;

    /**
     * CSS propre à la page Surveillance (chargé depuis lib/monitoring_page.css).
     */
    public static function pageCss(): string
    {
        static $css = null;
        if ($css === null) {
            $css = (string) file_get_contents(__DIR__ . '/../../lib/monitoring_page.css');
        }
        return $css;
    }

    /**
     * Entrées de navigation latérale spécifiques à la page Surveillance.
     *
     * @return array<string, array{href: string, label: string, icon: string}>
     */
    public static function navExtra(): array
    {
        return [
            'monitoring' => ['href' => 'index.php?p=monitoring',   'label' => 'Surveillance', 'icon' => '🖥'],
            'alerts'    => ['href' => 'index.php?p=admin_alerts', 'label' => 'Alertes', 'icon' => '🔔'],
            'stats'     => ['href' => 'index.php?p=stats',         'label' => 'Statistiques', 'icon' => '📈'],
            'backup'    => ['href' => 'index.php?p=backup',        'label' => 'Sauvegarde', 'icon' => '💾'],
            'health'    => ['href' => 'index.php?p=health',        'label' => 'Santé', 'icon' => '🏥'],
        ];
    }

    /**
     * Encart statistiques (en-tête).
     */
    public static function stats(MonitoringContext $ctx): string
    {
        return self::loadTemplate('monitoring_stats.php', [
            'total_sub'       => $ctx->total_sub,
            'taux_validation' => $ctx->taux_validation,
            'avg_days'        => $ctx->avg_days,
            'avg_hours'       => $ctx->avg_hours,
            'en_cours_sub'    => $ctx->en_cours_sub,
            'tokens_bloques'  => $ctx->tokens_bloques,
            'active_alerts'   => $ctx->active_alerts,
        ]);
    }

    /**
     * Carte avec graphique donut des statuts de soumission.
     */
    public static function repartition(int $total_sub, int $valide_sub, int $en_cours_sub, int $refuse_sub): string
    {
        return self::loadTemplate('monitoring_repartition.php', ['total_sub' => $total_sub, 'valide_sub' => $valide_sub, 'en_cours_sub' => $en_cours_sub, 'refuse_sub' => $refuse_sub]);
    }

    /**
     * Carte santé SMTP.
     */
    public static function smtpCard(string $smtp_status, string $smtp_detail, string $smtp_debug_log = ''): string
    {
        return self::loadTemplate('monitoring_smtp_card.php', ['smtp_status' => $smtp_status, 'smtp_detail' => $smtp_detail, 'smtp_debug_log' => $smtp_debug_log]);
    }

    /**
     * Carte scripts automatisés.
     */
    public static function scriptsCard(string $last_remind, string $last_alert_check): string
    {
        return self::loadTemplate('monitoring_scripts_card.php', ['last_remind' => $last_remind, 'last_alert_check' => $last_alert_check]);
    }

    /**
     * Carte alertes actives.
     *
     * @param array<int, array{days_remaining: int, form_label: string, nom_agent: string, deadline_formatted: string, pending_steps: int}> $active_alerts
     */
    public static function activeAlerts(array $active_alerts): string
    {
        return self::loadTemplate('monitoring_active_alerts.php', ['active_alerts' => $active_alerts]);
    }

    /**
     * Carte dernières alertes envoyées.
     *
     * @param array<int, array{sent_at: string, rule_label: string, form_label: string, message: string}> $recent_alerts
     */
    public static function recentAlerts(array $recent_alerts): string
    {
        return self::loadTemplate('monitoring_recent_alerts.php', ['recent_alerts' => $recent_alerts]);
    }

    /**
     * Carte soumissions par formulaire.
     *
     * @param array<int, array{total: int, valide: int, label: string, en_cours: int, refuse: int}> $by_form_stats
     */
    public static function byForm(array $by_form_stats): string
    {
        return self::loadTemplate('monitoring_by_form.php', ['by_form_stats' => $by_form_stats]);
    }

    /**
     * Carte activité des 7 derniers jours.
     *
     * @param array<int, array{cnt: int, day: string}> $daily_stats
     */
    public static function dailyActivity(array $daily_stats): string
    {
        return self::loadTemplate('monitoring_daily_activity.php', ['daily_stats' => $daily_stats]);
    }

    /**
     * Carte tokens bloqués.
     *
     * @param array<int, array{id: string, email: string, sent_at: string|null, relance_count: int, expires_at: string|null, step_label: string, ordre: int, submission_id: string, submitted_by: string|null, submitted_at: string|null, form_label: string}> $tokens_bloques
     */
    public static function blockedTokens(array $tokens_bloques, int $bloque_hours): string
    {
        return self::loadTemplate('monitoring_blocked_tokens.php', ['tokens_bloques' => $tokens_bloques, 'bloque_hours' => $bloque_hours]);
    }

    /**
     * Carte "Journal des emails".
     *
     * @param array<int, array{created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}> $mail_logs
     */
    public static function mailLogs(array $mail_logs): string
    {
        return self::loadTemplate('monitoring_mail_logs.php', ['mail_logs' => $mail_logs]);
    }

    /**
     * Compose l'ensemble du contenu HTML de la page Surveillance.
     */
    public static function content(MonitoringContext $ctx): string
    {
        $stats_html         = self::stats($ctx);
        $repartition_html   = self::repartition($ctx->total_sub, $ctx->valide_sub, $ctx->en_cours_sub, $ctx->refuse_sub);
        $smtp_html          = self::smtpCard($ctx->smtp_status, $ctx->smtp_detail, $ctx->smtp_debug_log);
        $scripts_html       = self::scriptsCard($ctx->last_remind, $ctx->last_alert_check);
        $active_alerts_html = self::activeAlerts($ctx->active_alerts);
        $recent_alerts_html = self::recentAlerts($ctx->recent_alerts);
        $by_form_html       = self::byForm($ctx->by_form_stats);
        $daily_html         = self::dailyActivity($ctx->daily_stats);
        $blocked_html       = self::blockedTokens($ctx->tokens_bloques, $ctx->bloque_hours);
        $mail_logs_html     = self::mailLogs($ctx->mail_logs);
        $audit_html         = self::auditLog($ctx);

        return <<<HTML
              <h1><span aria-hidden="true">🖥</span> Surveillance et diagnostic</h1>

            {$stats_html}

            {$repartition_html}

              <!-- Santé système -->
              <div class="grid-2">
            {$smtp_html}

            {$scripts_html}
              </div>

            {$active_alerts_html}

            {$recent_alerts_html}

            {$by_form_html}

            {$daily_html}

            {$blocked_html}

            {$mail_logs_html}

            {$audit_html}
            HTML;
    }

    /**
     * Carte journal d'audit (S5-B / Action 1).
     */
    public static function auditLog(MonitoringContext $ctx): string
    {
        return self::loadTemplate('monitoring_audit_log.php', [
            'audit_filters'     => $ctx->audit_filters,
            'audit_total'       => $ctx->audit_total,
            'audit_total_pages' => $ctx->audit_total_pages,
            'audit_page'        => $ctx->audit_page,
            'audit_logs'        => $ctx->audit_logs,
            'action_types'      => $ctx->action_types,
            'audit_base_url'    => $ctx->audit_base_url,
            'audit_base_qs'     => $ctx->audit_base_qs,
        ]);
    }

    /**
     * Charge un template PHP depuis le répertoire templates/ et retourne son contenu.
     *
     * Les variables passées dans $vars sont extract()ées dans le scope du template.
     */
    private static function loadTemplate(string $filename, array $vars = []): string
    {
        if (self::$templatesDir === null) {
            self::$templatesDir = __DIR__ . '/templates/';
        }
        $filepath = self::$templatesDir . $filename;
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Template not found: {$filepath}");
        }
        extract($vars, EXTR_OVERWRITE);
        ob_start();
        require $filepath;
        return ob_get_clean();
    }
}
