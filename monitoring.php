<?php
// monitoring.php — Tableau de bord de monitoring et observabilite
require_once __DIR__ . '/helpers.php';

if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

$pdo = get_pdo();

// ── Metrique : temps moyen de traitement ──
$avg_time_stmt = $pdo->query("
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
$total_sub = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$valide_sub = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'valide'")->fetchColumn();
$refuse_sub = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'refuse'")->fetchColumn();
$en_cours_sub = (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE status = 'en_cours'")->fetchColumn();
$taux_validation = $total_sub > 0 ? round(($valide_sub / $total_sub) * 100, 1) : 0;

// ── Tokens bloques (en attente depuis + de X jours) ──
$delai_relance = (int)get_setting('delai_relance_h', '48');
$bloque_hours = $delai_relance * 2; // Seuil : 2x le delai de relance
$tokens_bloques = $pdo->query("
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
$tokens_expired = $pdo->query("
    SELECT COUNT(*) FROM tokens t
    JOIN submissions s ON s.id = t.submission_id
    WHERE t.done_at IS NULL AND t.expires_at IS NOT NULL
      AND t.expires_at < datetime('now') AND s.status = 'en_cours'
")->fetchColumn();

// ── Alertes actives : soumissions en cours proches de la deadline ──
$active_alerts = [];
try {
    $alert_submissions = $pdo->query("
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
        $deadline_ts = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($deadline_str))) {
            $deadline_ts = strtotime(trim($deadline_str) . ' 00:00:00');
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($deadline_str), $m)) {
            $deadline_ts = strtotime("{$m[3]}-{$m[2]}-{$m[1]} 00:00:00");
        }

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
}

// ── Dernieres alertes envoyees ──
$recent_alerts = [];
try {
    $recent_alerts = $pdo->query("
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
}

// ── Test SMTP ──
$smtp_status = 'inconnu';
$smtp_detail = '';
if (isset($_GET['test_smtp']) && $_GET['test_smtp'] === '1') {
    $to = get_auth_user();
    $subject = 'Test SMTP — Monitoring FluxDREETS';
    $body = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#222;">
  <h2 style="color:#003189;">Test SMTP</h2>
  <p>Cet email confirme que le serveur SMTP est fonctionnel.</p>
  <p>Date : ' . h(date('d/m/Y H:i:s')) . '</p>
</body></html>';
    $smtp_ok = send_mail($to, $subject, $body);
    $smtp_status = $smtp_ok ? 'ok' : 'erreur';
    $smtp_detail = $smtp_ok ? 'Email de test envoyé avec succès à ' . h($to) : 'Échec de l\'envoi. Vérifiez la configuration SMTP.';
    app_log('smtp_test', 'smtp', $smtp_detail);
}

// ── Dernier remind (setting追踪) ──
$last_remind = get_setting('last_remind_run', '');

// ── Dernier alert_check ──
$last_alert_check = get_setting('last_alert_check', '');

// ── Soumissions par jour (7 derniers jours) ──
$daily_stmt = $pdo->query("
    SELECT DATE(submitted_at) as day, COUNT(*) as cnt
    FROM submissions
    WHERE submitted_at >= datetime('now', '-7 days')
    GROUP BY DATE(submitted_at)
    ORDER BY day DESC
");
$daily_stats = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Soumissions par formulaire ──
$by_form_stmt = $pdo->query("
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

// ── Audit log ──
$action_filter = $_GET['log_action'] ?? '';
$audit_logs = get_audit_logs(50, $action_filter);

// ── Types d'actions pour le filtre ──
$action_types = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitoring — FluxDREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    .container { max-width: 1200px; }
    .grid-2 { gap: 1.5rem; margin-bottom: 2rem; }

    /* Page-specific */
    .alert-row.urgent { background: #fde8e8 !important; }
    .alert-row.warning { background: #fff3e0 !important; }
    .alert-row.ok { background: #e8f5e9 !important; }
    .days-remaining { font-weight: bold; padding: .2rem .6rem; border-radius: var(--r-sm); font-size: .85rem; }
    .days-remaining.overdue { background: #c0392b; color: #fff; }
    .days-remaining.critical { background: #b45309; color: #fff; }
    .days-remaining.warning { background: #fff3e0; color: #b45309; }
    .days-remaining.ok { background: #e8f5e9; color: #1a6b3c; }

    @media (max-width: 768px) {
      .grid-2 { grid-template-columns: 1fr; }
    }

    /* Donut chart */
    .chart-row { display: flex; align-items: center; gap: 2rem; margin-bottom: 2rem; flex-wrap: wrap; }
    .donut-chart { width: 160px; height: 160px; border-radius: 50%; position: relative; flex-shrink: 0; }
    .donut-chart .donut-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80px; height: 80px; background: #fff; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .donut-chart .donut-center .donut-value { font-size: 1.5rem; font-weight: bold; color: var(--c-primary-dark); }
    .donut-chart .donut-center .donut-label { font-size: .7rem; color: var(--c-text-secondary); }
    .chart-legend { display: flex; flex-direction: column; gap: .5rem; }
    .legend-item { display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
    .legend-dot { width: 14px; height: 14px; border-radius: var(--r-sm); flex-shrink: 0; }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('monitoring', [
    'monitoring'=> ['href' => 'monitoring.php',   'label' => 'Monitoring', 'icon' => '🖥'],
    'alerts'    => ['href' => 'admin_alerts.php', 'label' => 'Alertes', 'icon' => '🔔'],
    'stats'     => ['href' => 'stats.php',         'label' => 'Statistiques', 'icon' => '📈'],
    'backup'    => ['href' => 'backup.php',        'label' => 'Sauvegarde', 'icon' => '💾'],
    'health'    => ['href' => 'health.php',        'label' => 'Santé', 'icon' => '🏥'],
]) ?>
<?= render_breadcrumb([['Accueil', 'index.php'], ['Monitoring']]) ?>
<main class="container" id="main-content">
  <h1><span aria-hidden="true">🖥</span> Monitoring & Observabilité</h1>

  <!-- Stats globales -->
  <div class="grid-3">
    <div class="stat-card">
      <div class="stat-value"><?= $total_sub ?></div>
      <div class="stat-label">Soumissions totales</div>
    </div>
    <div class="stat-card success">
      <div class="stat-value"><?= $taux_validation ?>%</div>
      <div class="stat-label">Taux de validation</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $avg_days > 0 ? $avg_days . ' j' : $avg_hours . ' h' ?></div>
      <div class="stat-label">Temps moyen de traitement</div>
    </div>
    <div class="stat-card warning">
      <div class="stat-value"><?= $en_cours_sub ?></div>
      <div class="stat-label">En cours</div>
    </div>
    <div class="stat-card danger">
      <div class="stat-value"><?= count($tokens_bloques) ?></div>
      <div class="stat-label">Tokens bloqués</div>
    </div>
    <div class="stat-card <?= !empty($active_alerts) ? 'danger' : 'success' ?>">
      <div class="stat-value"><?= count($active_alerts) ?></div>
      <div class="stat-label">Alertes actives</div>
    </div>
  </div>

  <!-- Graphique de répartition des statuts -->
  <div class="card">
    <h2><span aria-hidden="true">📊</span> Répartition des soumissions</h2>
    <?php
      // Calculer les proportions pour le donut chart
      $p_valide = $total_sub > 0 ? round(($valide_sub / $total_sub) * 100) : 0;
      $p_en_cours = $total_sub > 0 ? round(($en_cours_sub / $total_sub) * 100) : 0;
      $p_refuse = $total_sub > 0 ? round(($refuse_sub / $total_sub) * 100) : 0;
      // Ajuster pour que ça fasse 100%
      $diff = 100 - $p_valide - $p_en_cours - $p_refuse;
      $p_valide += $diff; // Compenser les arrondis

      // Construire le conic-gradient
      $g_valide_end = $p_valide;
      $g_en_cours_end = $p_valide + $p_en_cours;
      $g_refuse_end = 100;
      $gradient = "conic-gradient(#1a6b3c 0% {$g_valide_end}%, #b45309 {$g_valide_end}% {$g_en_cours_end}%, #c0392b {$g_en_cours_end}% 100%)";
    ?>
    <div class="chart-row">
      <div class="donut-chart" style="background: <?= $gradient ?>;">
        <div class="donut-center">
          <span class="donut-value"><?= $total_sub ?></span>
          <span class="donut-label">Total</span>
        </div>
      </div>
      <div class="chart-legend">
        <div class="legend-item">
          <span class="legend-dot" style="background:#1a6b3c;"></span>
          <strong>Validées</strong> : <?= $valide_sub ?> (<?= $p_valide ?>%)
        </div>
        <div class="legend-item">
          <span class="legend-dot" style="background:#b45309;"></span>
          <strong>En cours</strong> : <?= $en_cours_sub ?> (<?= $p_en_cours ?>%)
        </div>
        <div class="legend-item">
          <span class="legend-dot" style="background:#c0392b;"></span>
          <strong>Refusées</strong> : <?= $refuse_sub ?> (<?= $p_refuse ?>%)
        </div>
      </div>
    </div>
  </div>

  <!-- Santé système -->
  <div class="grid-2">
    <!-- Santé SMTP -->
    <div class="card">
      <h2><span aria-hidden="true">📧</span> Santé SMTP</h2>
      <p style="margin-bottom:1rem;">
        <?php if ($smtp_status === 'ok'): ?>
          <span class="health-dot health-ok"></span>
          <span class="badge badge-ok">Fonctionnel</span>
          <?= h($smtp_detail) ?>
        <?php elseif ($smtp_status === 'erreur'): ?>
          <span class="health-dot health-err"></span>
          <span class="badge badge-err">Erreur</span>
          <?= h($smtp_detail) ?>
        <?php else: ?>
          <span class="health-dot health-unknown"></span>
          <span class="badge badge-info">Non testé</span>
          Cliquez sur le bouton pour tester la connexion SMTP.
        <?php endif; ?>
      </p>
      <p style="font-size:.85rem;color:#595959;margin-bottom:1rem;">
        Hôte : <strong><?= h(get_setting('smtp_host', SMTP_HOST)) ?></strong> |
        Port : <strong><?= h(get_setting('smtp_port', (string)SMTP_PORT)) ?></strong> |
        Chiffrement : <strong><?= h(get_setting('smtp_secure', '') ?: 'Aucun') ?></strong>
      </p>
      <a href="?test_smtp=1" class="btn btn-primary">Tester SMTP</a>
    </div>

    <!-- Scripts automatises -->
    <div class="card">
      <h2><span aria-hidden="true">🤖</span> Scripts automatisés</h2>
      <!-- Script de relance -->
      <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #eee;">
        <strong style="font-size:.9rem;"><span aria-hidden="true">🔄</span> Script de relance (remind.php)</strong><br>
        <?php if ($last_remind): ?>
          <?php
            $remind_ts = strtotime($last_remind);
            $remind_age = ($remind_ts !== false) ? (time() - $remind_ts) : 999999;
            $remind_ok = $remind_age < 86400;
          ?>
          <span class="health-dot <?= $remind_ok ? 'health-ok' : 'health-warn' ?>" style="margin-top:.5rem;"></span>
          Dernière exécution : <strong><?= h(date('d/m/Y à H:i', strtotime($last_remind))) ?></strong>
          <?php if (!$remind_ok): ?>
            <br><span class="badge badge-warn" style="margin-top:.25rem;"><span aria-hidden="true">⚠</span> Il y a plus de 24h</span>
          <?php else: ?>
            <br><span class="badge badge-ok" style="margin-top:.25rem;"><span aria-hidden="true">✓</span> Actif</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="health-dot health-unknown"></span>
          <span class="badge badge-info">Jamais exécuté</span>
        <?php endif; ?>
      </div>
      <!-- Script d'alerte -->
      <div>
        <strong style="font-size:.9rem;"><span aria-hidden="true">🔔</span> Script d'alerte (alert_check.php)</strong><br>
        <?php if ($last_alert_check): ?>
          <?php
            $alert_ts = strtotime($last_alert_check);
            $alert_age = ($alert_ts !== false) ? (time() - $alert_ts) : 999999;
            $alert_ok = $alert_age < 86400;
          ?>
          <span class="health-dot <?= $alert_ok ? 'health-ok' : 'health-warn' ?>" style="margin-top:.5rem;"></span>
          Dernière exécution : <strong><?= h(date('d/m/Y à H:i', strtotime($last_alert_check))) ?></strong>
          <?php if (!$alert_ok): ?>
            <br><span class="badge badge-warn" style="margin-top:.25rem;"><span aria-hidden="true">⚠</span> Il y a plus de 24h</span>
          <?php else: ?>
            <br><span class="badge badge-ok" style="margin-top:.25rem;"><span aria-hidden="true">✓</span> Actif</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="health-dot health-unknown"></span>
          <span class="badge badge-info">Jamais exécuté</span>
        <?php endif; ?>
        <p style="font-size:.8rem;color:#595959;margin-top:.5rem;">
          Délai relance : <strong><?= h(get_setting('delai_relance_h', '48')) ?>h</strong> |
          Max relances : <strong><?= h(get_setting('relance_max', '3')) ?></strong> |
          Expiration tokens : <strong><?= h(get_setting('token_expire_days', '30')) ?>j</strong>
        </p>
      </div>
    </div>
  </div>

  <!-- Alertes actives : soumissions proches de la deadline -->
  <?php if (!empty($active_alerts)): ?>
  <div class="card">
    <h2><span aria-hidden="true">🔔</span> Alertes actives — Soumissions proches de la date cible</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Les soumissions suivantes sont en cours et approchent ou dépassent leur date cible avec des étapes non complétées.
    </p>
    <table>
      <thead>
        <tr><th>Urgence</th><th>Formulaire</th><th>Agent</th><th>Date cible</th><th>Jours restants</th><th>Étapes en attente</th></tr>
      </thead>
      <tbody>
      <?php foreach ($active_alerts as $aa):
        $row_cls = $aa['days_remaining'] < 0 ? 'urgent' : ($aa['days_remaining'] <= 2 ? 'urgent' : ($aa['days_remaining'] <= 5 ? 'warning' : 'ok'));
        $days_cls = $aa['days_remaining'] < 0 ? 'overdue' : ($aa['days_remaining'] <= 2 ? 'critical' : ($aa['days_remaining'] <= 5 ? 'warning' : 'ok'));
        $days_text = $aa['days_remaining'] < 0 ? 'J+' . abs($aa['days_remaining']) : ($aa['days_remaining'] === 0 ? "Jour J" : 'J-' . $aa['days_remaining']);
      ?>
        <tr class="alert-row <?= $row_cls ?>">
          <td><span class="days-remaining <?= $days_cls ?>"><?= $days_text ?></span></td>
          <td><strong><?= h($aa['form_label']) ?></strong></td>
          <td><?= h($aa['nom_agent']) ?></td>
          <td style="white-space:nowrap;"><?= h($aa['deadline_formatted']) ?></td>
          <td><span class="days-remaining <?= $days_cls ?>"><?= $days_text ?></span></td>
          <td><span class="badge badge-warn"><?= $aa['pending_steps'] ?> en attente</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:1rem;">
      <a href="admin_alerts.php" class="btn btn-secondary" style="font-size:.85rem;"><span aria-hidden="true">⚙</span> Configurer les règles d'alerte</a>
    </p>
  </div>
  <?php endif; ?>

  <!-- Dernieres alertes envoyees -->
  <?php if (!empty($recent_alerts)): ?>
  <div class="card">
    <h2><span aria-hidden="true">📬</span> Dernières alertes envoyées</h2>
    <table>
      <thead>
        <tr><th>Date</th><th>Règle</th><th>Formulaire</th><th>Message</th></tr>
      </thead>
      <tbody>
      <?php foreach ($recent_alerts as $ra): ?>
        <tr>
          <td style="white-space:nowrap;font-size:.8rem;"><?= h(date('d/m/Y H:i', strtotime($ra['sent_at']))) ?></td>
          <td><span class="badge badge-info"><?= h($ra['rule_label'] ?? 'Règle supprimée') ?></span></td>
          <td style="font-size:.85rem;"><?= h($ra['form_label']) ?></td>
          <td style="font-size:.8rem;"><?= h($ra['message']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Soumissions par formulaire -->
  <div class="card">
    <h2><span aria-hidden="true">📊</span> Soumissions par formulaire</h2>
    <?php if (empty($by_form_stats) || (count($by_form_stats) === 1 && $by_form_stats[0]['total'] == 0)): ?>
      <p class="empty-state">Aucune soumission enregistrée.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Formulaire</th><th>Total</th><th>En cours</th><th>Validées</th><th>Refusées</th><th>Taux validation</th></tr>
        </thead>
        <tbody>
        <?php foreach ($by_form_stats as $bf):
            $bf_total = (int)$bf['total'];
            $bf_valide = (int)$bf['valide'];
            $bf_rate = $bf_total > 0 ? round(($bf_valide / $bf_total) * 100, 1) : 0;
        ?>
          <tr>
            <td><strong><?= h($bf['label']) ?></strong></td>
            <td><?= $bf_total ?></td>
            <td><span class="badge badge-warn"><?= (int)$bf['en_cours'] ?></span></td>
            <td><span class="badge badge-ok"><?= $bf_valide ?></span></td>
            <td><span class="badge badge-err"><?= (int)$bf['refuse'] ?></span></td>
            <td><strong><?= $bf_rate ?>%</strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Activité récente (7 jours) -->
  <div class="card">
    <h2><span aria-hidden="true">📈</span> Activité des 7 derniers jours</h2>
    <?php if (empty($daily_stats)): ?>
      <p class="empty-state">Aucune soumission ces 7 derniers jours.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Date</th><th>Soumissions</th><th>Barre</th></tr></thead>
        <tbody>
        <?php
          $max_daily = max(array_column($daily_stats, 'cnt'));
          foreach ($daily_stats as $ds):
            $pct = $max_daily > 0 ? round(($ds['cnt'] / $max_daily) * 100) : 0;
        ?>
          <tr>
            <td style="white-space:nowrap;"><?= h(date('d/m/Y', strtotime($ds['day']))) ?></td>
            <td><strong><?= (int)$ds['cnt'] ?></strong></td>
            <td style="width:60%;"><div style="background:#003189;height:20px;border-radius:2px;width:<?= $pct ?>%;min-width:4px;"></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Tokens bloqués -->
  <div class="card">
    <h2><span aria-hidden="true">🚨</span> Tokens bloqués (en attente depuis + de <?= $bloque_hours ?>h)</h2>
    <?php if (empty($tokens_bloques)): ?>
      <p class="empty-state">Aucun token bloqué — tout est fluide !</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Formulaire</th><th>Étape</th><th>Validateur</th><th>Envoyé le</th><th>Relances</th><th>Expire le</th><th>Agent</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tokens_bloques as $tb): ?>
          <tr>
            <td><?= h($tb['form_label']) ?></td>
            <td><span class="badge badge-info">Étape <?= (int)$tb['ordre'] ?> — <?= h($tb['step_label']) ?></span></td>
            <td><?= h($tb['email']) ?></td>
            <td style="white-space:nowrap;"><?= h(date('d/m/Y H:i', strtotime($tb['sent_at']))) ?></td>
            <td><?= (int)$tb['relance_count'] ?></td>
            <td style="white-space:nowrap;"><?= $tb['expires_at'] ? h(date('d/m/Y', strtotime($tb['expires_at']))) : '—' ?></td>
            <td><?= h($tb['submitted_by']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Journal d'audit -->
  <div class="card">
    <h2><span aria-hidden="true">📝</span> Journal d'audit</h2>
    <div class="toolbar">
      <form method="GET" style="display:flex;gap:.5rem;align-items:center;">
        <label for="log_action" style="font-size:.85rem;font-weight:bold;">Filtrer par action :</label>
        <select name="log_action" id="log_action" class="form-filter">
          <option value="">Toutes les actions</option>
          <?php foreach ($action_types as $at): ?>
            <option value="<?= h($at) ?>" <?= $action_filter === $at ? 'selected' : '' ?>><?= h($at) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary" style="font-size:.8rem;padding:.3rem .8rem;">Filtrer</button>
      </form>
    </div>
    <?php if (empty($audit_logs)): ?>
      <p class="empty-state">Aucune entrée dans le journal d'audit.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Date</th><th>Action</th><th>Cible</th><th>Détail</th><th>Acteur</th><th>IP</th></tr>
        </thead>
        <tbody>
        <?php foreach ($audit_logs as $al): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.8rem;"><?= h(date('d/m/Y H:i', strtotime($al['created_at']))) ?></td>
            <td><span class="badge badge-info"><?= h($al['action']) ?></span></td>
            <td style="font-size:.8rem;"><?= h($al['target']) ?></td>
            <td style="font-size:.8rem;"><?= h($al['detail']) ?></td>
            <td style="font-size:.8rem;"><?= h($al['actor']) ?></td>
            <td style="font-size:.8rem;color:#595959;"><?= h($al['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</main>
<?= render_footer() ?>
</body>
</html>
