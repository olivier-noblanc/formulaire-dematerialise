<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\SubmissionField;
use App\Enum\SubmissionStatus;
use App\Forms\SubmissionData;

/**
 * Contrôleur de la page Surveillance (monitoring).
 */
final class MonitoringController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdminEffective();

        // Query #1: Average processing time
        $avgSeconds = $this->submissionRepo->getAvgProcessingTime();
        $avgHours = round($avgSeconds / 3600, 1);
        $avgDays = round($avgSeconds / 86400, 1);

        $gstats = App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
        $totalSub = $gstats['total'];
        $valideSub = $gstats[SubmissionStatus::Valide->value];
        $refuseSub = $gstats[SubmissionStatus::Refuse->value];
        $enCoursSub = $gstats[SubmissionStatus::EnCours->value];
        $tauxValidation = $gstats['taux_validation']; // float, matches MonitoringContext::$taux_validation

        // Query #2: Blocked tokens
        $delaiRelance = (int) App::settings()->get('delai_relance_h', '48');
        $bloqueHours = $delaiRelance * 2;
        /** @var list<array{id: string, email: string, sent_at: string|null, relance_count: int, expires_at: string|null, step_label: string, ordre: int, submission_id: string, submitted_by: string|null, submitted_at: string|null, form_label: string}> $tokensBloques */
        $tokensBloques = $this->tokenRepo->findBlocked($bloqueHours);

        // Query #3: Expired tokens
        $this->tokenRepo->countExpired();

        // Query #4 & #5: Active submissions with deadlines + batch pending counts
        $activeAlerts = [];
        try {
            $alertSubmissions = $this->submissionRepo->findActiveWithDeadlineField();

            $nowTs = time();

            // Batch fetch pending token counts to avoid N+1
            $alertSubIds = array_column($alertSubmissions, 'id');
            $pendingCounts = $alertSubIds !== []
                ? $this->tokenRepo->countPendingBySubmissionIds($alertSubIds)
                : [];

            foreach ($alertSubmissions as $alertSubmission) {
                $data = json_decode($alertSubmission['data'], true) ?? [];
                $deadlineField = $alertSubmission['deadline_field'];
                $deadlineStr = $data[$deadlineField] ?? '';
                if ($deadlineStr === '') {
                    continue;
                }
                if ($deadlineStr === '0') {
                    continue;
                }

                $deadlineTs = parse_deadline_date($deadlineStr);
                if (!((bool)$deadlineTs)) {
                    continue;
                }

                $daysRemaining = (int) floor(($deadlineTs - $nowTs) / 86400);
                $pendingCount = $pendingCounts[$alertSubmission['id']] ?? 0;

                if ($daysRemaining <= 10) {
                    $nomAgent = SubmissionData::get($data, SubmissionField::PRENOM) . ' ' . SubmissionData::get($data, SubmissionField::NOM);
                    $activeAlerts[] = [
                        'submission_id' => $alertSubmission['id'],
                        'form_label' => $alertSubmission['form_label'],
                        'nom_agent' => $nomAgent,
                        'deadline' => trim((string) $deadlineStr),
                        'deadline_formatted' => date('d/m/Y', $deadlineTs),
                        'days_remaining' => $daysRemaining,
                        'pending_steps' => $pendingCount,
                        'submitted_by' => $alertSubmission['submitted_by'],
                    ];
                }
            }
            usort($activeAlerts, fn(array $a, array $b): int|float => $a['days_remaining'] - $b['days_remaining']);
        } catch (\Exception) {
            // @silent-ok: fallback uses empty array on read failure
            $activeAlerts = [];
        }
 
        // Query #6: Recent alerts (existing repo method)
        $recentAlerts = [];
        try {
            /** @var list<array{id: string, rule_id: string, submission_id: string, sent_at: string, message: string|null, form_label: string, rule_label: string|null}> $recentAlerts */
            $recentAlerts = $this->alertRepo->getLogsWithForm(20);
        } catch (\Exception) {
            // @silent-ok: fallback uses empty array on read failure
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

        /** @var list<array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}> $mailLogs */
        $mailLogs = App::mail()->getRecentLogs(20);
        $lastRemind = App::settings()->get('last_remind_run', '');
        $lastAlertCheck = App::settings()->get('last_alert_check', '');

        // Query #7: Daily activity
        /** @var list<array{day: string, cnt: int}> $dailyStats */
        $dailyStats = $this->submissionRepo->getDailyCounts(7);

        // Query #8: Per-form stats
        /** @var list<array{label: string, total: int, en_cours: int, valide: int, refuse: int}> $byFormStats */
        $byFormStats = $this->formRepo->getSubmissionCounts();

        // Audit filters
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

        $auditPage = max(1, (int) ($_GET['log_page'] ?? 1));
        $auditPerPage = 50;

        // Query #9: Audit CSV export
        if (isset($_GET['export_audit']) && $_GET['export_audit'] === '1') {
            // B-02-9 fix (audit 2026-07-26) : audit_log était enregistré AVANT la génération
            // du CSV. Si la génération échouait (DB lock, OOM), l'audit disait 'export done'
            // alors que rien n'avait été exporté. Maintenant on log APRÈS succès.
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
            $auditExportRows = $this->auditRepo->findFiltered($auditFilters);
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($out, ['Date', 'Action', 'Acteur', 'Cible', 'Détail', 'IP'], ';', '"', '\\');
                foreach ($auditExportRows as $arow) {
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
            // B-02-9 fix : audit log APRÈS succès du export CSV
            App::audit()->log('audit_export', 'audit_log', 'Export CSV du journal d\'audit filtré');
            exit;
        }

        // Query #10: Audit count
        $auditTotal = $this->auditRepo->countFiltered($auditFilters);
        $auditTotalPages = max(1, (int) ceil($auditTotal / $auditPerPage));
        if ($auditPage > $auditTotalPages) {
            $auditPage = $auditTotalPages;
        }
        $auditOffset = ($auditPage - 1) * $auditPerPage;

        // Query #11: Audit paginated
        /** @var list<array{id: string, action: string, target: string|null, detail: string|null, actor: string, ip: string|null, created_at: string}> $auditLogs */
        $auditLogs = $this->auditRepo->findFilteredPaginated($auditFilters, $auditPerPage, $auditOffset);

        // Query #12: Distinct action types
        /** @var list<string> $actionTypes */
        $actionTypes = $this->auditRepo->getDistinctActionTypes();

        $auditBaseQs = http_build_query(array_filter([
            'log_action'     => $auditFilters['log_action'],
            'log_actor'      => $auditFilters['log_actor'],
            'log_target'     => $auditFilters['log_target'],
            'log_date_debut' => $auditFilters['log_date_debut'],
            'log_date_fin'   => $auditFilters['log_date_fin'],
        ], fn(string $v): bool => $v !== ''));
        $auditBaseUrl = 'index.php?p=monitoring' . ($auditBaseQs !== '' && $auditBaseQs !== '0' ? '?' . $auditBaseQs : '');

        $ctx = new \App\Render\MonitoringContext(
            total_sub: $totalSub,
            valide_sub: $valideSub,
            en_cours_sub: $enCoursSub,
            refuse_sub: $refuseSub,
            taux_validation: $tauxValidation,
            avg_days: $avgDays,
            avg_hours: $avgHours,
            bloque_hours: $bloqueHours,
            tokens_bloques: $tokensBloques,
            active_alerts: $activeAlerts,
            recent_alerts: $recentAlerts,
            by_form_stats: $byFormStats,
            daily_stats: $dailyStats,
            smtp_status: $smtpStatus,
            smtp_detail: $smtpDetail,
            smtp_debug_log: $smtpDebugLog,
            mail_logs: $mailLogs,
            last_remind: $lastRemind,
            last_alert_check: $lastAlertCheck,
            audit_filters: $auditFilters,
            audit_total: $auditTotal,
            audit_total_pages: $auditTotalPages,
            audit_page: $auditPage,
            audit_logs: $auditLogs,
            action_types: $actionTypes,
            audit_base_url: $auditBaseUrl,
            audit_base_qs: $auditBaseQs,
        );

        $pageCss    = \App\Render\MonitoringRenderer::pageCss();
        $navExtra   = \App\Render\MonitoringRenderer::navExtra();
        $content    = \App\Render\MonitoringRenderer::content($ctx);

        echo new \App\Render\PageRenderer()->page('Surveillance', 'monitoring', $pageCss, $content, ['nav_extra' => $navExtra]);
    }
}
