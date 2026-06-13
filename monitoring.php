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

// ── Test SMTP ──
$smtp_status = 'inconnu';
$smtp_detail = '';
if (isset($_GET['test_smtp']) && $_GET['test_smtp'] === '1') {
    $to = get_auth_user();
    $subject = 'Test SMTP — Monitoring Workflow DREETS';
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
  <title>Monitoring — DREETS Workflow</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    .container { max-width: 1200px; }
    .grid-2 { gap: 1.5rem; margin-bottom: 2rem; }

    /* Page-specific */
    @media (max-width: 768px) {
      .grid-2 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span>Connecté en tant que : <strong><?= h(get_auth_user()) ?></strong></span>
  <span>
    <a href="dashboard.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;">📊 Dashboard</a>
    <a href="admin_settings.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">⚙ Paramètres</a>
    <a href="docs.php" style="color:#b3c8f0;font-size:.8rem;text-decoration:none;margin-left:8px;">📖 Documentation</a>
  </span>
</div>
<div class="container">
  <h1>🖥 Monitoring & Observabilité</h1>

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
    <div class="stat-card <?= $tokens_expired > 0 ? 'danger' : 'success' ?>">
      <div class="stat-value"><?= (int)$tokens_expired ?></div>
      <div class="stat-label">Tokens expirés non traités</div>
    </div>
  </div>

  <!-- Santé système -->
  <div class="grid-2">
    <!-- Santé SMTP -->
    <div class="card">
      <h2>📧 Santé SMTP</h2>
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
      <p style="font-size:.85rem;color:#888;margin-bottom:1rem;">
        Hôte : <strong><?= h(get_setting('smtp_host', SMTP_HOST)) ?></strong> |
        Port : <strong><?= h(get_setting('smtp_port', (string)SMTP_PORT)) ?></strong> |
        Chiffrement : <strong><?= h(get_setting('smtp_secure', '') ?: 'Aucun') ?></strong>
      </p>
      <a href="?test_smtp=1" class="btn btn-primary">Tester SMTP</a>
    </div>

    <!-- Dernier remind -->
    <div class="card">
      <h2>🔄 Script de relance</h2>
      <?php if ($last_remind): ?>
        <?php
          $remind_age = time() - strtotime($last_remind);
          $remind_ok = $remind_age < 86400; // moins de 24h
        ?>
        <p style="margin-bottom:.5rem;">
          <span class="health-dot <?= $remind_ok ? 'health-ok' : 'health-warn' ?>"></span>
          Dernière exécution : <strong><?= h(date('d/m/Y à H:i', strtotime($last_remind))) ?></strong>
          <?php if (!$remind_ok): ?>
            <br><span class="badge badge-warn" style="margin-top:.5rem;">⚠ Dernière exécution il y a plus de 24h — vérifiez la tâche planifiée</span>
          <?php else: ?>
            <br><span class="badge badge-ok" style="margin-top:.5rem;">✓ Script actif</span>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p>
          <span class="health-dot health-unknown"></span>
          <span class="badge badge-info">Jamais exécuté</span>
          Le script de relance (remind.php) n'a jamais été lancé ou ne trace pas son exécution.
        </p>
      <?php endif; ?>
      <p style="font-size:.85rem;color:#888;margin-top:1rem;">
        Délai de relance : <strong><?= h(get_setting('delai_relance_h', '48')) ?>h</strong> |
        Max relances : <strong><?= h(get_setting('relance_max', '3')) ?></strong> |
        Expiration tokens : <strong><?= h(get_setting('token_expire_days', '30')) ?> jours</strong>
      </p>
    </div>
  </div>

  <!-- Soumissions par formulaire -->
  <div class="card">
    <h2>📊 Soumissions par formulaire</h2>
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
    <h2>📈 Activité des 7 derniers jours</h2>
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
    <h2>🚨 Tokens bloqués (en attente depuis + de <?= $bloque_hours ?>h)</h2>
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
    <h2>📝 Journal d'audit</h2>
    <div class="toolbar">
      <form method="GET" style="display:flex;gap:.5rem;align-items:center;">
        <label for="log_action" style="font-size:.85rem;font-weight:bold;">Filtrer par action :</label>
        <select name="log_action" id="log_action" class="form-filter" onchange="this.form.submit()">
          <option value="">Toutes les actions</option>
          <?php foreach ($action_types as $at): ?>
            <option value="<?= h($at) ?>" <?= $action_filter === $at ? 'selected' : '' ?>><?= h($at) ?></option>
          <?php endforeach; ?>
        </select>
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
            <td style="font-size:.8rem;color:#888;"><?= h($al['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>
<?= render_footer() ?>
</body>
</html>
