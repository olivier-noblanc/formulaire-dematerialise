<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Statistiques.
 */
final class StatsController extends BaseController
{
    public function handle(): void
    {
        App::auth()->requireAdmin();

        $pdo = $this->db->getPdo();
        $period = $_GET['period'] ?? 'month';
        if (!in_array($period, ['week', 'month', 'year'])) $period = 'month';

        $globalStats = App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
        $periodStats = App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod($period, 12);

        $formStats = $pdo->query("
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
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $validatorStats = $pdo->query("
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
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $periodLabel = $period === 'week' ? 'semaine' : ($period === 'year' ? 'année' : 'mois');

        $navExtra = [
            'stats'     => ['href' => 'index.php?p=stats',        'label' => 'Statistiques', 'icon' => '📈'],
            'monitoring'=> ['href' => 'index.php?p=monitoring',    'label' => 'Surveillance', 'icon' => '🖥'],
        ];
        ob_start();
        ?>
  <h1><span aria-hidden="true">📊</span> Statistiques</h1>

  <div class="period-tabs">
    <a href="index.php?p=stats&period=week" class="<?= $period === 'week' ? 'active' : '' ?>">Par semaine</a>
    <a href="index.php?p=stats&period=month" class="<?= $period === 'month' ? 'active' : '' ?>">Par mois</a>
    <a href="index.php?p=stats&period=year" class="<?= $period === 'year' ? 'active' : '' ?>">Par année</a>
  </div>

  <div class="grid-3">
    <div class="stat-card">
      <div class="stat-value"><?= $globalStats['total'] ?></div>
      <div class="stat-label">Soumissions totales</div>
    </div>
    <div class="stat-card success">
      <div class="stat-value"><?= $globalStats['taux_validation'] ?>%</div>
      <div class="stat-label">Taux de validation</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $globalStats['avg_days'] ?> j</div>
      <div class="stat-label">Temps moyen de traitement</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $globalStats['today'] ?></div>
      <div class="stat-label">Aujourd'hui</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $globalStats['this_week'] ?></div>
      <div class="stat-label">Cette semaine</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $globalStats['this_month'] ?></div>
      <div class="stat-label">Ce mois</div>
    </div>
  </div>

  <div class="card">
    <h2>Répartition des statuts</h2>
    <?= App::html()->renderDonutChart((int)$globalStats['total'], (int)$globalStats['valide'], (int)$globalStats['en_cours'], (int)$globalStats['refuse']) ?>
  </div>

  <div class="card">
    <h2>Évolution par <?= $periodLabel ?></h2>
    <?php if (empty($periodStats)): ?>
      <p class="empty-state">Aucune donnée pour cette période.</p>
    <?php else: ?>
      <?php
        $column = array_column($periodStats, 'total');
        $maxTotal = $column !== [] ? (max($column) ?: 1) : 1;
        $periodStatsAsc = array_reverse($periodStats);
      ?>
      <div class="bar-chart">
        <?php foreach ($periodStatsAsc as $ps):
          $pct = round(($ps['total'] / $maxTotal) * 100);
          $validePct = $ps['total'] > 0 ? round(($ps['valide'] / $ps['total']) * 100) : 0;
          $enCoursPct = $ps['total'] > 0 ? round(($ps['en_cours'] / $ps['total']) * 100) : 0;
          $refusePct = max(0, 100 - $validePct - $enCoursPct);
          $avgDays = !empty($ps['avg_processing_seconds']) ? round((float)$ps['avg_processing_seconds'] / 86400, 1) : '—';
        ?>
        <div class="bar-row">
          <div class="bar-label"><?= \App\Core\App::html()->escape($ps['period']) ?></div>
          <div class="bar-track">
            <div class="stacked-bar" style="width:<?= max($pct, 3) ?>%;">
              <div class="segment-valide" style="width:<?= $validePct ?>%;"></div>
              <div class="segment-en_cours" style="width:<?= $enCoursPct ?>%;"></div>
              <div class="segment-refuse" style="width:<?= $refusePct ?>%;"></div>
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

  <div class="card">
    <h2>Performance par formulaire</h2>
    <?php if (empty($formStats) || (count($formStats) === 1 && $formStats[0]['total'] == 0)): ?>
      <p class="empty-state">Aucune soumission enregistrée.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Formulaire</th><th>Total</th><th>En cours</th><th>Validées</th><th>Refusées</th><th>Taux</th><th>Temps moyen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($formStats as $fs):
          $fsTotal = (int)$fs['total'];
          $fsValide = (int)$fs['valide'];
          $fsRate = $fsTotal > 0 ? round(($fsValide / $fsTotal) * 100, 1) : 0;
          $fsAvg = !empty($fs['avg_seconds']) ? round((float)$fs['avg_seconds'] / 86400, 1) . ' j' : '—';
        ?>
          <tr>
            <td><strong><?= \App\Core\App::html()->escape($fs['label']) ?></strong></td>
            <td><?= $fsTotal ?></td>
            <td><span class="badge badge-warn"><?= (int)$fs['en_cours'] ?></span></td>
            <td><span class="badge badge-ok"><?= $fsValide ?></span></td>
            <td><span class="badge badge-err"><?= (int)$fs['refuse'] ?></span></td>
            <td><strong><?= $fsRate ?>%</strong></td>
            <td><?= $fsAvg ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Performance par validateur</h2>
    <?php if (empty($validatorStats)): ?>
      <p class="empty-state">Aucune donnée de validation.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Validateur</th><th>Total assigné</th><th>Traitées</th><th>En attente</th><th>Temps de réponse moyen</th></tr>
        </thead>
        <tbody>
        <?php foreach ($validatorStats as $vs):
          $vsAvg = !empty($vs['avg_response_seconds']) ? round((float)$vs['avg_response_seconds'] / 3600, 1) . ' h' : '—';
        ?>
          <tr>
            <td><?= App::html()->displayUser($vs['email']) ?></td>
            <td><?= (int)$vs['total'] ?></td>
            <td><span class="badge badge-ok"><?= (int)$vs['done'] ?></span></td>
            <td><span class="badge badge-warn"><?= (int)$vs['pending'] ?></span></td>
            <td><?= $vsAvg ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Volume de données</h2>
    <div class="grid-2">
      <div class="stat-card">
        <div class="stat-value"><?= $globalStats['tokens_pending'] ?></div>
        <div class="stat-label">Tokens en attente</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= $globalStats['attachments_count'] ?></div>
        <div class="stat-label">Pièces jointes</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= App::html()->formatFileSize($globalStats['attachments_size']) ?></div>
        <div class="stat-label">Volume pièces jointes</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= App::html()->formatFileSize(App::webhook()->getDbSize()) ?></div>
        <div class="stat-label">Taille base de données</div>
      </div>
    </div>
  </div>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Statistiques', 'stats', '', $content, ['nav_extra' => $navExtra]);
    }
}
