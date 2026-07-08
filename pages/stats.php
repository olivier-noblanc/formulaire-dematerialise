<?php
// stats.php — Statistiques et tableaux de bord par période
require_once dirname(__DIR__) . '/helpers.php';

require_admin();

$pdo = \App\Core\App::db()->getPdo();
$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['week', 'month', 'year'])) $period = 'month';

// Récupérer les statistiques
// A-13: audit N+1 — La page exécute 4 requêtes agrégées (déjà optimisées en SUM(CASE WHEN...))
//   - get_global_stats() : helpers.php — 11 COUNT séparés (hors périmètre, traité par Agent 1)
//   - get_stats_by_period() : helpers.php — déjà batchée (GROUP BY + SUM(CASE WHEN...))
//   - $form_stats : ci-dessous — déjà batchée (LEFT JOIN + SUM(CASE WHEN...))
//   - $validator_stats : ci-dessous — déjà batchée (JOIN + SUM(CASE WHEN...))
// Aucun N+1 sur la page elle-même (les boucles d'affichage ne font aucune requête SQL).
$global_stats = get_global_stats();
$period_stats = get_stats_by_period($period, 12);

// Statistiques par formulaire
$form_stats = _dbm_q($pdo, "
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
$validator_stats = _dbm_q($pdo, "
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

// Label de période
$period_label = $period === 'week' ? 'semaine' : ($period === 'year' ? 'année' : 'mois');
?>
<?php
$page_css = '';
$nav_extra = [
    'stats'     => ['href' => 'index.php?p=stats',        'label' => 'Statistiques', 'icon' => '📈'],
    'monitoring'=> ['href' => 'index.php?p=monitoring',    'label' => 'Surveillance', 'icon' => '🖥'],
];
ob_start();
?>
  <h1><span aria-hidden="true">📊</span> Statistiques</h1>

  <!-- Sélecteur de période -->
  <div class="period-tabs">
    <a href="index.php?p=stats&period=week" class="<?= $period === 'week' ? 'active' : '' ?>">Par semaine</a>
    <a href="index.php?p=stats&period=month" class="<?= $period === 'month' ? 'active' : '' ?>">Par mois</a>
    <a href="index.php?p=stats&period=year" class="<?= $period === 'year' ? 'active' : '' ?>">Par année</a>
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
    <?= render_donut_chart((int)$global_stats['total'], (int)$global_stats['valide'], (int)$global_stats['en_cours'], (int)$global_stats['refuse']) ?>
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
            <td><?= display_user($vs['email']) ?></td>
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
        <div class="stat-value"><?= format_file_size(get_db_size()) ?></div>
        <div class="stat-label">Taille base de données</div>
      </div>
    </div>
  </div>

<?php
$content = ob_get_clean();
if ($content === false) { $content = ''; }
echo render_page('Statistiques', 'stats', $page_css, $content, ['nav_extra' => $nav_extra]);
