<?php
declare(strict_types=1);

/**
 * Rendu de la page Surveillance (monitoring.php) — Wrapper backward-compatible.
 *
 * Les fonctions globales déléguent à App\Render\MonitoringRenderer (OOP).
 * Ce fichier assure la rétrocompatibilité avec tous les appels existants.
 *
 * @package lib
 * @see /monitoring.php
 * @see /src/Render/MonitoringRenderer.php
 */

use App\Render\MonitoringRenderer;

function monitoring_page_css(): string
{
    return MonitoringRenderer::pageCss();
}

function monitoring_nav_extra(): array
{
    return MonitoringRenderer::navExtra();
}

function render_monitoring_stats(array $ctx): string
{
    return MonitoringRenderer::stats($ctx);
}

function render_monitoring_repartition(int $total_sub, int $valide_sub, int $en_cours_sub, int $refuse_sub): string
{
    return MonitoringRenderer::repartition($total_sub, $valide_sub, $en_cours_sub, $refuse_sub);
}

function render_monitoring_smtp_card(string $smtp_status, string $smtp_detail, string $smtp_debug_log = ''): string
{
    return MonitoringRenderer::smtpCard($smtp_status, $smtp_detail, $smtp_debug_log);
}

function render_monitoring_scripts_card(string $last_remind, string $last_alert_check): string
{
    return MonitoringRenderer::scriptsCard($last_remind, $last_alert_check);
}

function render_monitoring_active_alerts(array $active_alerts): string
{
    return MonitoringRenderer::activeAlerts($active_alerts);
}

function render_monitoring_recent_alerts(array $recent_alerts): string
{
    return MonitoringRenderer::recentAlerts($recent_alerts);
}

function render_monitoring_by_form(array $by_form_stats): string
{
    return MonitoringRenderer::byForm($by_form_stats);
}

function render_monitoring_daily_activity(array $daily_stats): string
{
    return MonitoringRenderer::dailyActivity($daily_stats);
}

function render_monitoring_blocked_tokens(array $tokens_bloques, int $bloque_hours): string
{
    return MonitoringRenderer::blockedTokens($tokens_bloques, $bloque_hours);
}

function render_monitoring_mail_logs(array $mail_logs): string
{
    return MonitoringRenderer::mailLogs($mail_logs);
}

function render_monitoring_content(array $ctx): string
{
    return MonitoringRenderer::content($ctx);
}

function render_monitoring_audit_log(array $ctx): string
{
    return MonitoringRenderer::auditLog($ctx);
}
