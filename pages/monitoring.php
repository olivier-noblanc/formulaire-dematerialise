<?php
// monitoring.php — Tableau de bord de monitoring et observabilite
//
// Le rendu HTML est extrait vers lib/render_monitoring.php (+ lib/render_monitoring_audit.php
// pour la section journal d'audit) pour garder ce fichier sous 600 lignes
// (refactor « all-under-600 »). Ce fichier ne contient plus que le data fetching.
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/lib/render_monitoring.php';
require_once dirname(__DIR__) . '/lib/render_monitoring_audit.php';
use App\Core\App;

require_admin();

$pdo = get_pdo();

// ── Metrique : temps moyen de traitement ──
$avg_time_stmt = _dbm_q($pdo, "
    SELECT AVG(
        CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL)
    ) as avg_seconds
    FROM submissions s
    WHERE s.status = 'valide' AND s.closed_at IS NOT NULL
");
$avg_seconds = (float)($avg_time_stmt->fetchColumn() ?: 0);
$avg_hours = round($avg_seconds / 3600, 1);
$avg_days = round($avg_seconds / 86400, 1);

// ── Metrique : taux de validation ──
$gstats = get_global_stats();
$total_sub = $gstats['total'];
$valide_sub = $gstats['valide'];
$refuse_sub = $gstats['refuse'];
$en_cours_sub = $gstats['en_cours'];
$taux_validation = $gstats['taux_validation'];

// ── Tokens bloques (en attente depuis + de X jours) ──
$delai_relance = (int)\App\Core\App::settings()->get('delai_relance_h', '48');
$bloque_hours = $delai_relance * 2; // Seuil : 2x le delai de relance
$tokens_bloques = _dbm_q($pdo, "
    SELECT t.id, t.email, t.sent_at, t.relance_count, t.expires_at,
           st.label as step_label, st.ordre,
           s.id as submission_id, s.submitted_by, s.submitted_at,
           f.label as form_label
    FROM tokens t
    JOIN steps st ON st.id = t.step_id
    JOIN submissions s ON s.id = t.submission_id
    JOIN forms f ON f.id = s.form_id
    WHERE t.done_at IS NULL AND s.status = 'en_cours'
      AND CAST(strftime('%s', 'now') AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL) > ($bloque_hours * 3600)
    ORDER BY t.sent_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Tokens expires non traites ──
$tokens_expired = _dbm_q($pdo, "
    SELECT COUNT(*) FROM tokens t
    JOIN submissions s ON s.id = t.submission_id
    WHERE t.done_at IS NULL AND t.expires_at IS NOT NULL
      AND t.expires_at < datetime('now') AND s.status = 'en_cours'
")->fetchColumn();

// ── Alertes actives : soumissions en cours proches de la deadline ──
$active_alerts = [];
try {
    $alert_submissions = _dbm_q($pdo, "
        SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.form_id,
               f.label as form_label, f.deadline_field
        FROM submissions s
        JOIN forms f ON f.id = s.form_id
        WHERE s.status = 'en_cours' AND f.deadline_field != ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    $now_ts = time();
    foreach ($alert_submissions as $as) {
        $data = json_decode($as['data'], true) ?: [];
        $deadline_field = $as['deadline_field'];
        $deadline_str = $data[$deadline_field] ?? '';
        if (empty($deadline_str)) continue;

        // Parser la date (format YYYY-MM-DD ou DD/MM/YYYY)
        $deadline_ts = parse_deadline_date($deadline_str);

        if (!$deadline_ts) continue;

        $days_remaining = (int)(($deadline_ts - $now_ts) / 86400);

        // Compter les tokens en attente
        $pending = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND done_at IS NULL");
        $pending->execute([$as['id']]);
        $pending_count = (int)$pending->fetchColumn();

        // Ne montrer que si : dans les 10 jours OU deja depasse
        if ($days_remaining <= 10) {
            $nom_agent = ($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '');
            $active_alerts[] = [
                'submission_id' => $as['id'],
                'form_label' => $as['form_label'],
                'nom_agent' => $nom_agent,
                'deadline' => trim($deadline_str),
                /** @phpstan-ignore-next-line ternary.alwaysTrue */
                'deadline_formatted' => $deadline_ts ? date('d/m/Y', $deadline_ts) : $deadline_str,
                'days_remaining' => $days_remaining,
                'pending_steps' => $pending_count,
                'submitted_by' => $as['submitted_by'],
            ];
        }
    }
    // Trier : les plus urgents d'abord
    usort($active_alerts, fn($a, $b) => $a['days_remaining'] - $b['days_remaining']);
} catch (Exception $e) {
    $active_alerts = [];
    $error_msg = ($error_msg ?? '') . 'Erreur alertes actives : ' . $e->getMessage() . '. ';
}

// ── Dernieres alertes envoyees ──
$recent_alerts = [];
try {
    $recent_alerts = _dbm_q($pdo, "
        SELECT al.*, f.label as form_label, ar.label as rule_label
        FROM alert_log al
        JOIN submissions s ON s.id = al.submission_id
        JOIN forms f ON f.id = s.form_id
        LEFT JOIN alert_rules ar ON ar.id = al.rule_id
        ORDER BY al.sent_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_alerts = [];
    $error_msg = ($error_msg ?? '') . 'Erreur alertes récentes : ' . $e->getMessage() . '. ';
}

// ── Test SMTP ──
$smtp_status = 'inconnu';
$smtp_detail = '';
$smtp_debug_log = '';  // conversation SMTP complète (uniquement si test demandé)
if (isset($_GET['test_smtp']) && $_GET['test_smtp'] === '1') {
    $to = get_auth_user();
    $subject = 'Test SMTP — Surveillance ' . get_app_name();
    $body = render_email_template(
        'Test SMTP',
        '<p>Cet email confirme que le serveur SMTP est fonctionnel.</p>
  <p>Date : ' . h(date('d/m/Y H:i:s')) . '</p>'
    );
    // Utiliser send_mail_detailed() pour récupérer l'erreur et la conversation SMTP
    $smtp_result = send_mail_detailed($to, $subject, $body);
    $smtp_ok = $smtp_result['success'];
    $smtp_status = $smtp_ok ? 'ok' : 'erreur';
    if ($smtp_ok) {
        $smtp_detail = 'Email de test envoyé avec succès à ' . h($to);
    } else {
        // Détail enrichi : message d'erreur + statut (blocked / cli_blocked / etc.)
        $err = $smtp_result['error'] !== '' ? $smtp_result['error'] : 'Erreur inconnue';
        $smtp_detail = 'Échec de l\'envoi à ' . h($to) . ' — ' . h($err) . ' (statut: ' . h($smtp_result['status']) . ')';
    }
    $smtp_debug_log = $smtp_result['smtp_log'];
    App::audit()->log('smtp_test', 'smtp', $smtp_detail);
}

// ── Récupération des derniers logs mail (table mail_log v23+) ──
$mail_logs = get_recent_mail_logs(20);

// ── Dernier remind (setting追踪) ──
$last_remind = \App\Core\App::settings()->get('last_remind_run', '');

// ── Dernier alert_check ──
$last_alert_check = \App\Core\App::settings()->get('last_alert_check', '');

// ── Soumissions par jour (7 derniers jours) ──
$daily_stmt = _dbm_q($pdo, "
    SELECT DATE(submitted_at) as day, COUNT(*) as cnt
    FROM submissions
    WHERE submitted_at >= datetime('now', '-7 days')
    GROUP BY DATE(submitted_at)
    ORDER BY day DESC
");
$daily_stats = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Soumissions par formulaire ──
$by_form_stmt = _dbm_q($pdo, "
    SELECT f.label, COUNT(s.id) as total,
           SUM(CASE WHEN s.status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
           SUM(CASE WHEN s.status = 'valide' THEN 1 ELSE 0 END) as valide,
           SUM(CASE WHEN s.status = 'refuse' THEN 1 ELSE 0 END) as refuse
    FROM forms f
    LEFT JOIN submissions s ON s.form_id = f.id
    GROUP BY f.id
    ORDER BY total DESC
");
$by_form_stats = $by_form_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Audit log — filtres avancés + pagination + export CSV (S5-B / Action 1) ──
// Mme Laurent (DSI) a noté traçabilité 3.1/10 — l'audit log n'était pas filtrable.
// On ajoute : filtres (date début/fin, action, acteur, cible), pagination 50/page,
// export CSV. Sécurité : prepared statements uniquement, noms de colonnes codés en dur.
$audit_filters = [
    'log_action'     => trim($_GET['log_action'] ?? ''),
    'log_actor'      => trim($_GET['log_actor'] ?? ''),
    'log_target'     => trim($_GET['log_target'] ?? ''),
    'log_date_debut' => trim($_GET['log_date_debut'] ?? ''),
    'log_date_fin'   => trim($_GET['log_date_fin'] ?? ''),
];
// Sécurité (A-01) : valider le format date YYYY-MM-DD (input type="date")
foreach (['log_date_debut', 'log_date_fin'] as $_df) {
    if ($audit_filters[$_df] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $audit_filters[$_df])) {
        $audit_filters[$_df] = '';
    }
}

$audit_page     = max(1, (int)($_GET['log_page'] ?? 1));
$audit_per_page = 50;

// Construction de la clause WHERE — noms de colonnes codés en dur (pas d'input utilisateur)
$audit_where  = [];
$audit_params = [];
if ($audit_filters['log_action'] !== '') {
    $audit_where[]  = 'action = ?';
    $audit_params[] = $audit_filters['log_action'];
}
if ($audit_filters['log_actor'] !== '') {
    $audit_where[]  = 'actor LIKE ?';
    $audit_params[] = '%' . $audit_filters['log_actor'] . '%';
}
if ($audit_filters['log_target'] !== '') {
    $audit_where[]  = 'target LIKE ?';
    $audit_params[] = '%' . $audit_filters['log_target'] . '%';
}
if ($audit_filters['log_date_debut'] !== '') {
    $audit_where[]  = 'date(created_at) >= ?';
    $audit_params[] = $audit_filters['log_date_debut'];
}
if ($audit_filters['log_date_fin'] !== '') {
    $audit_where[]  = 'date(created_at) <= ?';
    $audit_params[] = $audit_filters['log_date_fin'];
}
$audit_where_sql = $audit_where ? ('WHERE ' . implode(' AND ', $audit_where)) : '';

// Export CSV du journal filtré
if (isset($_GET['export_audit']) && $_GET['export_audit'] === '1') {
    App::audit()->log('audit_export', 'audit_log', 'Export CSV du journal d\'audit filtré');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $audit_export_stmt = $pdo->prepare("SELECT created_at, action, actor, target, detail, ip FROM audit_log $audit_where_sql ORDER BY created_at DESC");
    $audit_export_stmt->execute($audit_params);
    $out = fopen('php://output', 'w');
    if ($out === false) { $out = null; }
    // BOM UTF-8 pour Excel
    if ($out !== null) { fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); }
    if ($out !== null) { fputcsv($out, ['Date', 'Action', 'Acteur', 'Cible', 'Détail', 'IP'], ';', '"', '\\'); }
    while ($arow = $audit_export_stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($out !== null) {
            fputcsv($out, [
                $arow['created_at'],
                $arow['action'],
                $arow['actor'],
                $arow['target']  ?? '',
                $arow['detail']  ?? '',
                $arow['ip']      ?? '',
            ], ';', '"', '\\');
        }
    }
    if ($out !== null) { fclose($out); }
    exit;
}

// Comptage pour pagination
$audit_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log $audit_where_sql");
$audit_count_stmt->execute($audit_params);
$audit_total = (int)$audit_count_stmt->fetchColumn();
$audit_total_pages = max(1, (int)ceil($audit_total / $audit_per_page));
if ($audit_page > $audit_total_pages) $audit_page = $audit_total_pages;
$audit_offset = ($audit_page - 1) * $audit_per_page;

// Requête paginée (LIMIT/OFFSET en paramètres entiers)
$audit_stmt = $pdo->prepare("SELECT * FROM audit_log $audit_where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$audit_stmt->execute(array_merge($audit_params, [$audit_per_page, $audit_offset]));
$audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Types d'actions pour le filtre (valeurs distinctes de la DB) ──
$action_types = _dbm_q($pdo, "SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// URL de base pour la pagination (préserve tous les filtres actifs)
$audit_base_qs = http_build_query(array_filter([
    'log_action'     => $audit_filters['log_action'],
    'log_actor'      => $audit_filters['log_actor'],
    'log_target'     => $audit_filters['log_target'],
    'log_date_debut' => $audit_filters['log_date_debut'],
    'log_date_fin'   => $audit_filters['log_date_fin'],
], fn($v) => $v !== ''));
$audit_base_url = 'index.php?p=monitoring' . ($audit_base_qs ? '?' . $audit_base_qs : '');

// ── RENDU ──────────────────────────────────────────────────────
// Le rendu HTML est délégué à lib/render_monitoring.php (+ lib/render_monitoring_audit.php
// pour la section journal d'audit) pour garder ce fichier sous 600 lignes
// (refactor « all-under-600 »). L'ordre des sections reproduit exactement
// le rendu historique.

$ctx = [
    'total_sub'         => $total_sub,
    'valide_sub'        => $valide_sub,
    'en_cours_sub'      => $en_cours_sub,
    'refuse_sub'        => $refuse_sub,
    'taux_validation'   => $taux_validation,
    'avg_days'          => $avg_days,
    'avg_hours'         => $avg_hours,
    'tokens_bloques'    => $tokens_bloques,
    'bloque_hours'      => $bloque_hours,
    'active_alerts'     => $active_alerts,
    'recent_alerts'     => $recent_alerts,
    'by_form_stats'     => $by_form_stats,
    'daily_stats'       => $daily_stats,
    'smtp_status'       => $smtp_status,
    'smtp_detail'       => $smtp_detail,
    'smtp_debug_log'    => $smtp_debug_log,
    'mail_logs'         => $mail_logs,
    'last_remind'       => $last_remind,
    'last_alert_check'  => $last_alert_check,
    'audit_filters'     => $audit_filters,
    'audit_total'       => $audit_total,
    'audit_total_pages' => $audit_total_pages,
    'audit_page'        => $audit_page,
    'audit_logs'        => $audit_logs,
    'action_types'      => $action_types,
    'audit_base_url'    => $audit_base_url,
    'audit_base_qs'     => $audit_base_qs,
];

$page_css    = monitoring_page_css();
$nav_extra   = monitoring_nav_extra();
$content     = render_monitoring_content($ctx);

echo render_page('Surveillance', 'monitoring', $page_css, $content, ['nav_extra' => $nav_extra]);
