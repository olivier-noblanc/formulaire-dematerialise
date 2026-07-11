<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Surveillance (monitoring).
 */
final class MonitoringController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        require_once dirname(__DIR__, 2) . '/lib/render_monitoring_audit.php';

        $pdo = $this->db->getPdo();

        $avgTimeStmt = $pdo->query("
            SELECT AVG(
                CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
            ) as avg_seconds
            FROM submissions s
            WHERE s.status = 'valide' AND s.closed_at IS NOT NULL
        ");
        $avgSeconds = (float)($avgTimeStmt->fetchColumn() ?: 0);
        $avgHours = round($avgSeconds / 3600, 1);
        $avgDays = round($avgSeconds / 86400, 1);

        $gstats = App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
        $totalSub = $gstats['total'];
        $valideSub = $gstats['valide'];
        $refuseSub = $gstats['refuse'];
        $enCoursSub = $gstats['en_cours'];
        $tauxValidation = $gstats['taux_validation'];

        $delaiRelance = (int)App::settings()->get('delai_relance_h', '48');
        $bloqueHours = $delaiRelance * 2;
        $tokensBloques = $pdo->query("
            SELECT t.id, t.email, t.sent_at, t.relance_count, t.expires_at,
                   st.label as step_label, st.ordre,
                   s.id as submission_id, s.submitted_by, s.submitted_at,
                   f.label as form_label
            FROM tokens t
            JOIN steps st ON st.id = t.step_id
            JOIN submissions s ON s.id = t.submission_id
            JOIN forms f ON f.id = s.form_id
            WHERE t.done_at IS NULL AND s.status = 'en_cours'
              AND CAST(strftime('%s', 'now') AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL) > ($bloqueHours * 3600)
            ORDER BY t.sent_at ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $tokensExpired = $pdo->query("
            SELECT COUNT(*) FROM tokens t
            JOIN submissions s ON s.id = t.submission_id
            WHERE t.done_at IS NULL AND t.expires_at IS NOT NULL
              AND t.expires_at < datetime('now') AND s.status = 'en_cours'
        ")->fetchColumn();

        $activeAlerts = [];
        try {
            $alertSubmissions = $pdo->query("
                SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.form_id,
                       f.label as form_label, f.deadline_field
                FROM submissions s
                JOIN forms f ON f.id = s.form_id
                WHERE s.status = 'en_cours' AND f.deadline_field != ''
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $nowTs = time();
            foreach ($alertSubmissions as $as) {
                $data = json_decode($as['data'], true) ?: [];
                $deadlineField = $as['deadline_field'];
                $deadlineStr = $data[$deadlineField] ?? '';
                if (empty($deadlineStr)) continue;

                $deadlineTs = parse_deadline_date($deadlineStr);
                if (!$deadlineTs) continue;

                $daysRemaining = (int)(($deadlineTs - $nowTs) / 86400);

                $pending = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND done_at IS NULL");
                $pending->execute([$as['id']]);
                $pendingCount = (int)$pending->fetchColumn();

                if ($daysRemaining <= 10) {
                    $nomAgent = ($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '');
                    $activeAlerts[] = [
                        'submission_id' => $as['id'],
                        'form_label' => $as['form_label'],
                        'nom_agent' => $nomAgent,
                        'deadline' => trim($deadlineStr),
                        'deadline_formatted' => $deadlineTs ? date('d/m/Y', $deadlineTs) : $deadlineStr,
                        'days_remaining' => $daysRemaining,
                        'pending_steps' => $pendingCount,
                        'submitted_by' => $as['submitted_by'],
                    ];
                }
            }
            usort($activeAlerts, fn($a, $b) => $a['days_remaining'] - $b['days_remaining']);
        } catch (\Exception $e) {
            $activeAlerts = [];
        }

        $recentAlerts = [];
        try {
            $recentAlerts = $pdo->query("
                SELECT al.*, f.label as form_label, ar.label as rule_label
                FROM alert_log al
                JOIN submissions s ON s.id = al.submission_id
                JOIN forms f ON f.id = s.form_id
                LEFT JOIN alert_rules ar ON ar.id = al.rule_id
                ORDER BY al.sent_at DESC
                LIMIT 20
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $recentAlerts = [];
        }

        $smtpStatus = 'inconnu';
        $smtpDetail = '';
        $smtpDebugLog = '';
        if (isset($_GET['test_smtp']) && $_GET['test_smtp'] === '1') {
            $to = App::auth()->getUser();
            $subject = 'Test SMTP — Surveillance ' . \App\Render\NavigationRenderer::getAppName();
            $body = App::mail()->renderEmailTemplate(
                'Test SMTP',
                '<p>Cet email confirme que le serveur SMTP est fonctionnel.</p>
      <p>Date : ' . \App\Core\App::html()->escape(date('d/m/Y H:i:s')) . '</p>'
            );
            $smtpResult = App::mail()->sendDetailed($to, $subject, $body);
            $smtpOk = $smtpResult['success'];
            $smtpStatus = $smtpOk ? 'ok' : 'erreur';
            if ($smtpOk) {
                $smtpDetail = 'Email de test envoyé avec succès à ' . \App\Core\App::html()->escape($to);
            } else {
                $err = $smtpResult['error'] !== '' ? $smtpResult['error'] : 'Erreur inconnue';
                $smtpDetail = 'Échec de l\'envoi à ' . \App\Core\App::html()->escape($to) . ' — ' . \App\Core\App::html()->escape($err) . ' (statut: ' . \App\Core\App::html()->escape($smtpResult['status']) . ')';
            }
            $smtpDebugLog = $smtpResult['smtp_log'];
            App::audit()->log('smtp_test', 'smtp', $smtpDetail);
        }

        $mailLogs = App::mail()->getRecentLogs(20);
        $lastRemind = App::settings()->get('last_remind_run', '');
        $lastAlertCheck = App::settings()->get('last_alert_check', '');

        $dailyStmt = $pdo->query("
            SELECT DATE(submitted_at) as day, COUNT(*) as cnt
            FROM submissions
            WHERE submitted_at >= datetime('now', '-7 days')
            GROUP BY DATE(submitted_at)
            ORDER BY day DESC
        ");
        $dailyStats = $dailyStmt->fetchAll(\PDO::FETCH_ASSOC);

        $byFormStmt = $pdo->query("
            SELECT f.label, COUNT(s.id) as total,
                   SUM(CASE WHEN s.status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                   SUM(CASE WHEN s.status = 'valide' THEN 1 ELSE 0 END) as valide,
                   SUM(CASE WHEN s.status = 'refuse' THEN 1 ELSE 0 END) as refuse
            FROM forms f
            LEFT JOIN submissions s ON s.form_id = f.id
            GROUP BY f.id
            ORDER BY total DESC
        ");
        $byFormStats = $byFormStmt->fetchAll(\PDO::FETCH_ASSOC);

        $auditFilters = [
            'log_action'     => trim($_GET['log_action'] ?? ''),
            'log_actor'      => trim($_GET['log_actor'] ?? ''),
            'log_target'     => trim($_GET['log_target'] ?? ''),
            'log_date_debut' => trim($_GET['log_date_debut'] ?? ''),
            'log_date_fin'   => trim($_GET['log_date_fin'] ?? ''),
        ];
        foreach (['log_date_debut', 'log_date_fin'] as $_df) {
            if ($auditFilters[$_df] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditFilters[$_df])) {
                $auditFilters[$_df] = '';
            }
        }

        $auditPage = max(1, (int)($_GET['log_page'] ?? 1));
        $auditPerPage = 50;

        $auditWhere = [];
        $auditParams = [];
        if ($auditFilters['log_action'] !== '') {
            $auditWhere[]  = 'action = ?';
            $auditParams[] = $auditFilters['log_action'];
        }
        if ($auditFilters['log_actor'] !== '') {
            $auditWhere[]  = 'actor LIKE ?';
            $auditParams[] = '%' . $auditFilters['log_actor'] . '%';
        }
        if ($auditFilters['log_target'] !== '') {
            $auditWhere[]  = 'target LIKE ?';
            $auditParams[] = '%' . $auditFilters['log_target'] . '%';
        }
        if ($auditFilters['log_date_debut'] !== '') {
            $auditWhere[]  = 'date(created_at) >= ?';
            $auditParams[] = $auditFilters['log_date_debut'];
        }
        if ($auditFilters['log_date_fin'] !== '') {
            $auditWhere[]  = 'date(created_at) <= ?';
            $auditParams[] = $auditFilters['log_date_fin'];
        }
        $auditWhereSql = $auditWhere ? ('WHERE ' . implode(' AND ', $auditWhere)) : '';

        if (isset($_GET['export_audit']) && $_GET['export_audit'] === '1') {
            App::audit()->log('audit_export', 'audit_log', 'Export CSV du journal d\'audit filtré');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
            $auditExportStmt = $pdo->prepare("SELECT created_at, action, actor, target, detail, ip FROM audit_log $auditWhereSql ORDER BY created_at DESC");
            $auditExportStmt->execute($auditParams);
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($out, ['Date', 'Action', 'Acteur', 'Cible', 'Détail', 'IP'], ';', '"', '\\');
                while ($arow = $auditExportStmt->fetch(\PDO::FETCH_ASSOC)) {
                    fputcsv($out, [
                        $arow['created_at'],
                        $arow['action'],
                        $arow['actor'],
                        $arow['target']  ?? '',
                        $arow['detail']  ?? '',
                        $arow['ip']      ?? '',
                    ], ';', '"', '\\');
                }
                fclose($out);
            }
            exit;
        }

        $auditCountStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log $auditWhereSql");
        $auditCountStmt->execute($auditParams);
        $auditTotal = (int)$auditCountStmt->fetchColumn();
        $auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));
        if ($auditPage > $auditTotalPages) $auditPage = $auditTotalPages;
        $auditOffset = ($auditPage - 1) * $auditPerPage;

        $auditStmt = $pdo->prepare("SELECT * FROM audit_log $auditWhereSql ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $auditStmt->execute(array_merge($auditParams, [$auditPerPage, $auditOffset]));
        $auditLogs = $auditStmt->fetchAll(\PDO::FETCH_ASSOC);

        $actionTypes = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(\PDO::FETCH_COLUMN);

        $auditBaseQs = http_build_query(array_filter([
            'log_action'     => $auditFilters['log_action'],
            'log_actor'      => $auditFilters['log_actor'],
            'log_target'     => $auditFilters['log_target'],
            'log_date_debut' => $auditFilters['log_date_debut'],
            'log_date_fin'   => $auditFilters['log_date_fin'],
        ], fn($v) => $v !== ''));
        $auditBaseUrl = 'index.php?p=monitoring' . ($auditBaseQs ? '?' . $auditBaseQs : '');

        $ctx = [
            'total_sub'         => $totalSub,
            'valide_sub'        => $valideSub,
            'en_cours_sub'      => $enCoursSub,
            'refuse_sub'        => $refuseSub,
            'taux_validation'   => $tauxValidation,
            'avg_days'          => $avgDays,
            'avg_hours'         => $avgHours,
            'tokens_bloques'    => $tokensBloques,
            'bloque_hours'      => $bloqueHours,
            'active_alerts'     => $activeAlerts,
            'recent_alerts'     => $recentAlerts,
            'by_form_stats'     => $byFormStats,
            'daily_stats'       => $dailyStats,
            'smtp_status'       => $smtpStatus,
            'smtp_detail'       => $smtpDetail,
            'smtp_debug_log'    => $smtpDebugLog,
            'mail_logs'         => $mailLogs,
            'last_remind'       => $lastRemind,
            'last_alert_check'  => $lastAlertCheck,
            'audit_filters'     => $auditFilters,
            'audit_total'       => $auditTotal,
            'audit_total_pages' => $auditTotalPages,
            'audit_page'        => $auditPage,
            'audit_logs'        => $auditLogs,
            'action_types'      => $actionTypes,
            'audit_base_url'    => $auditBaseUrl,
            'audit_base_qs'     => $auditBaseQs,
        ];

        $pageCss    = \App\Render\MonitoringRenderer::pageCss();
        $navExtra   = \App\Render\MonitoringRenderer::navExtra();
        $content    = \App\Render\MonitoringRenderer::content($ctx);

        echo (new \App\Render\NavigationRenderer())->page('Surveillance', 'monitoring', $pageCss, $content, ['nav_extra' => $navExtra]);
    }
}
