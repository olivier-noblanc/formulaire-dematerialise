<?php
// stats.php — Statistiques et tableaux de bord par période
require_once __DIR__ . '/helpers.php';

if (!is_admin_user()) {
    header('Location: admin_access.php');
    exit;
}

$pdo = get_pdo();
$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['week', 'month', 'year'])) $period = 'month';

// Récupérer les statistiques
$global_stats = get_global_stats();
$period_stats = get_stats_by_period($period, 12);

// Statistiques par formulaire
$form_stats = $pdo->query("
    SELECT f.label, f.slug, COUNT(s.id) as total,
           SUM(CASE WHEN s.status = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
           SUM(CASE WHEN s.status = 'valide' THEN 1 ELSE 0 END) as valide,
           SUM(CASE WHEN s.status = 'refuse' THEN 1 ELSE 0 END) as refuse,
           AVG(CASE WHEN s.status = 'valide' AND s.closed_at IS NOT NULL 
               THEN CAST(strftime('%s', s.closed_at) AS REAL) - CAST(strftime('%s', s.submitted_at) AS REAL) 
               ELSE NULL END) as avg_seconds
    FROM forms f
    LEFT JOIN submissions s ON s.form_id = f.id
    GROUP BY f.id
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Statistiques par validateur
$validator_stats = $pdo->query("
    SELECT t.email,
           COUNT(t.id) as total,
           SUM(CASE WHEN t.done_at IS NOT NULL THEN 1 ELSE 0 END) as done,
           SUM(CASE WHEN t.done_at IS NULL THEN 1 ELSE 0 END) as pending,
           AVG(CASE WHEN t.done_at IS NOT NULL 
               THEN CAST(strftime('%s', t.done_at) AS REAL) - CAST(strftime('%s', t.sent_at) AS REAL) 
               ELSE NULL END) as avg_response_seconds
    FROM tokens t
    JOIN submissions s ON s.id = t.submission_id
    WHERE s.status = 'en_cours' OR t.done_at IS NOT NULL
    GROUP BY t.email
    ORDER BY total DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Calculer les proportions pour le donut chart
$total = $global_stats['total'];
$p_valide = $total > 0 ? round(($global_stats['valide'] / $total) * 100) : 0;
$p_en_cours = $total > 0 ? round(($global_stats['en_cours'] / $total) * 100) : 0;
$p_refuse = 100 - $p_valide - $p_en_cours;
$g_valide_end = $p_valide;
$g_en_cours_end = $p_valide + $p_en_cours;
$gradient = "conic-gradient(#1a6b3c 0% {$g_valide_end}%, #b45309 {$g_valide_end}% {$g_en_cours_end}%, #c0392b {$g_en_cours_end}% 100%)";

// Label de période
$period_label = $period === 'week' ? 'semaine' : ($period === 'year' ? 'année' : 'mois');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Statistiques — FluxDémat</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>F</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    .container { max-width: 1200px; }
    .period-tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
    .period-tabs a { padding: .5rem 1.25rem; border: 1px solid var(--c-primary-dark); border-radius: var(--r-sm); text-decoration: none; font-size: .85rem; color: var(--c-primary-dark); }
    .period-tabs a.active { background: var(--c-primary-dark); color: var(--c-text-inverse); }

    /* Bar chart */
    .bar-chart { margin-bottom: 1.5rem; }
    .bar-row { display: flex; align-items: center; gap: .75rem; margin-bottom: .5rem; }
    .bar-label { width: 90px; text-align: right; font-size: .8rem; color: #555; flex-shrink: 0; white-space: nowrap; }
    .bar-track { flex: 1; background: var(--c-border-light); border-radius: var(--r-sm); height: 28px; position: relative; overflow: hidden; }
    .bar-fill { height: 100%; border-radius: var(--r-sm); display: flex; align-items: center; padding-left: .5rem; font-size: .75rem; color: var(--c-text-inverse); font-weight: bold; min-width: 20px; }
    .bar-fill.valide { background: #1a6b3c; }
    .bar-fill.en_cours { background: #b45309; }
    .bar-fill.refuse { background: #c0392b; }
    .bar-fill.total { background: var(--c-primary-dark); }
    .bar-value { font-size: .8rem; color: #555; width: 40px; text-align: right; flex-shrink: 0; }

    /* Stacked bar for period stats */
    .stacked-bar { display: flex; height: 28px; border-radius: var(--r-sm); overflow: hidden; }
    .stacked-bar .segment-valide { background: #1a6b3c; }
    .stacked-bar .segment-en_cours { background: #b45309; }
    .stacked-bar .segment-refuse { background: #c0392b; }

    /* Donut chart */
    .chart-row { display: flex; align-items: center; gap: 2rem; margin-bottom: 2rem; flex-wrap: wrap; }
    .donut-chart { width: 160px; height: 160px; border-radius: 50%; position: relative; flex-shrink: 0; }
    .donut-chart .donut-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80px; height: 80px; background: #fff; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .donut-chart .donut-center .donut-value { font-size: 1.5rem; font-weight: bold; color: var(--c-primary-dark); }
    .donut-chart .donut-center .donut-label { font-size: .7rem; color: var(--c-text-secondary); }
    .chart-legend { display: flex; flex-direction: column; gap: .5rem; }
    .legend-item { display: flex; align-items: center; gap: .5rem; font-size: .85rem; }
    .legend-dot { width: 14px; height: 14px; border-radius: var(--r-sm); flex-shrink: 0; }

    @media (max-width: 768px) {
      .grid-2 { grid-template-columns: 1fr; }
      .bar-label { width: 70px; font-size: .7rem; }
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('stats', [
    'stats'     => ['href' => 'stats.php',        'label' => 'Statistiques', 'icon' => '📈'],
    'monitoring'=> ['href' => 'monitoring.php',    'label' => 'Surveillance', 'icon' => '🖥'],
]) ?>
<?= render_breadcrumb([['Accueil', 'index.php'], ['Statistiques']]) ?>
<main class="container" id="main-content">
  <h1><span aria-hidden="true">📊</span> Statistiques</h1>

  <!-- Sélecteur de période -->
  <div class="period-tabs">
    <a href="?period=week" class="<?= $period === 'week' ? 'active' : '' ?>">Par semaine</a>
    <a href="?period=month" class="<?= $period === 'month' ? 'active' : '' ?>">Par mois</a>
    <a href="?period=year" class="<?= $period === 'year' ? 'active' : '' ?>">Par année</a>
  </div>

  <!-- Stats globales -->
  <div class="grid-3">
    <div class="stat-card">
      <div class="stat-value"><?= $global_stats['total'] ?></div>
      <div class="stat-label">Soumissions totales</div>
    </div>
    <div class="stat-card success">
      <div class="stat-value"><?= $global_stats['taux_validation'] ?>%</div>
      <div class="stat-label">Taux de validation</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $global_stats['avg_days'] ?> j</div>
      <div class="stat-label">Temps moyen de traitement</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $global_stats['today'] ?></div>
      <div class="stat-label">Aujourd'hui</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $global_stats['this_week'] ?></div>
      <div class="stat-label">Cette semaine</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $global_stats['this_month'] ?></div>
      <div class="stat-label">Ce mois</div>
    </div>
  </div>

  <!-- Répartition des statuts -->
  <div class="card">
    <h2>Répartition des statuts</h2>
    <div class="chart-row">
      <div class="donut-chart" style="background: <?= $gradient ?>;">
        <div class="donut-center">
          <span class="donut-value"><?= $total ?></span>
          <span class="donut-label">Total</span>
        </div>
      </div>
      <div class="chart-legend">
        <div class="legend-item">
          <span class="legend-dot" style="background:#1a6b3c;"></span>
          <strong>Validées</strong> : <?= $global_stats['valide'] ?> (<?= $p_valide ?>%)
        </div>
        <div class="legend-item">
          <span class="legend-dot" style="background:#b45309;"></span>
          <strong>En cours</strong> : <?= $global_stats['en_cours'] ?> (<?= $p_en_cours ?>%)
        </div>
        <div class="legend-item">
          <span class="legend-dot" style="background:#c0392b;"></span>
          <strong>Refusées</strong> : <?= $global_stats['refuse'] ?> (<?= $p_refuse ?>%)
        </div>
      </div>
    </div>
  </div>

  <!-- Soumissions par période -->
  <div class="card">
    <h2>Évolution par <?= $period_label ?></h2>
    <?php if (empty($period_stats)): ?>
      <p class="empty-state">Aucune donnée pour cette période.</p>
    <?php else: ?>
      <?php
        $max_total = max(array_column($period_stats, 'total')) ?: 1;
        $period_stats_asc = array_reverse($period_stats);
      ?>
      <div class="bar-chart">
        <?php foreach ($period_stats_asc as $ps):
          $pct = round(($ps['total'] / $max_total) * 100);
          $valide_pct = $ps['total'] > 0 ? round(($ps['valide'] / $ps['total']) * 100) : 0;
          $en_cours_pct = $ps['total'] > 0 ? round(($ps['en_cours'] / $ps['total']) * 100) : 0;
          $refuse_pct = max(0, 100 - $valide_pct - $en_cours_pct);
          $avg_days = !empty($ps['avg_processing_seconds']) ? round((float)$ps['avg_processing_seconds'] / 86400, 1) : '—';
        ?>
        <div class="bar-row">
          <div class="bar-label"><?= h($ps['period']) ?></div>
          <div class="bar-track">
            <div class="stacked-bar" style="width:<?= max($pct, 3) ?>%;">
              <div class="segment-valide" style="width:<?= $valide_pct ?>%;"></div>
              <div class="segment-en_cours" style="width:<?= $en_cours_pct ?>%;"></div>
              <div class="segment-refuse" style="width:<?= $refuse_pct ?>%;"></div>
            </div>
          </div>
          <div class="bar-value"><?= (int)$ps['total'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="chart-legend" style="flex-direction:row;gap:1.5rem;margin-top:1rem;">
        <div class="legend-item"><span class="legend-dot" style="background:#1a6b3c;"></span>Validées</div>
        <div class="legend-item"><span class="legend-dot" style="background:#b45309;"></span>En cours</div>
        <div class="legend-item"><span class="legend-dot" style="background:#c0392b;"></span>Refusées</div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Par formulaire -->
  <div class="card">
    <h2>Performance par formulaire</h2>
    <?php if (empty($form_stats) || (count($form_stats) === 1 && $form_stats[0]['total'] == 0)): ?>
      <p class="empty-state">Aucune soumission enregistrée.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Formulaire</th><th>Total</th><th>En cours</th><th>Validées</th><th>Refusées</th><th>Taux</th><th>Temps moyen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($form_stats as $fs):
          $fs_total = (int)$fs['total'];
          $fs_valide = (int)$fs['valide'];
          $fs_rate = $fs_total > 0 ? round(($fs_valide / $fs_total) * 100, 1) : 0;
          $fs_avg = !empty($fs['avg_seconds']) ? round((float)$fs['avg_seconds'] / 86400, 1) . ' j' : '—';
        ?>
          <tr>
            <td><strong><?= h($fs['label']) ?></strong></td>
            <td><?= $fs_total ?></td>
            <td><span class="badge badge-warn"><?= (int)$fs['en_cours'] ?></span></td>
            <td><span class="badge badge-ok"><?= $fs_valide ?></span></td>
            <td><span class="badge badge-err"><?= (int)$fs['refuse'] ?></span></td>
            <td><strong><?= $fs_rate ?>%</strong></td>
            <td><?= $fs_avg ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Par validateur -->
  <div class="card">
    <h2>Performance par validateur</h2>
    <?php if (empty($validator_stats)): ?>
      <p class="empty-state">Aucune donnée de validation.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Validateur</th><th>Total assigné</th><th>Traitées</th><th>En attente</th><th>Temps de réponse moyen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($validator_stats as $vs):
          $vs_avg = !empty($vs['avg_response_seconds']) ? round((float)$vs['avg_response_seconds'] / 3600, 1) . ' h' : '—';
        ?>
          <tr>
            <td><?= h($vs['email']) ?></td>
            <td><?= (int)$vs['total'] ?></td>
            <td><span class="badge badge-ok"><?= (int)$vs['done'] ?></span></td>
            <td><span class="badge badge-warn"><?= (int)$vs['pending'] ?></span></td>
            <td><?= $vs_avg ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Volume de données -->
  <div class="card">
    <h2>Volume de données</h2>
    <div class="grid-2">
      <div class="stat-card">
        <div class="stat-value"><?= $global_stats['tokens_pending'] ?></div>
        <div class="stat-label">Tokens en attente</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= $global_stats['attachments_count'] ?></div>
        <div class="stat-label">Pièces jointes</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= format_file_size($global_stats['attachments_size']) ?></div>
        <div class="stat-label">Volume pièces jointes</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= format_file_size(filesize(defined('DB_PATH') ? DB_PATH : __DIR__ . '/db/workflow.db')) ?></div>
        <div class="stat-label">Taille base de données</div>
      </div>
    </div>
  </div>

</main>
<?= render_footer() ?>
</body>
</html>
