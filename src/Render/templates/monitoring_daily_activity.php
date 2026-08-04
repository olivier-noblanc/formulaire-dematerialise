<?php
if ($daily_stats === []) {
    $body = '<p class="empty-state">Aucune soumission ces 7 derniers jours.</p>';
} else {
    $column = array_column($daily_stats, 'cnt');
    $max_daily = $column !== [] ? max($column) : 0;
    $rows = '';
    foreach ($daily_stats as $daily_stat) {
        $cnt = (int) $daily_stat['cnt'];
        $pct = $max_daily > 0 ? round(($cnt / $max_daily) * 100) : 0;
        $date = \App\Core\App::html()->escape(date('d/m/Y', (int) strtotime((string) $daily_stat['day'])));
        $pct_cls = 'mp-' . (int) $pct;
        \App\Core\App::css()->rule($pct_cls, "background:#003189;height:20px;border-radius:2px;width:{$pct}%;min-width:4px;");
        $rows .= <<<HTML
                  <tr>
                    <td class="u-whi">{$date}</td>
                    <td><strong>{$cnt}</strong></td>
                    <td class="progress-fill-2"><div class="{$pct_cls}"></div></td>
                  </tr>
        HTML;
    }
    $body = <<<HTML
              <table>
                <thead><tr><th>Date</th><th>Soumissions</th><th>Barre</th></tr></thead>
                <tbody>
                {$rows}
                </tbody>
              </table>
    HTML;
}
?>
<!-- Activité récente (7 jours) -->
<div class="card">
  <h2><span aria-hidden="true">📈</span> Activité des 7 derniers jours</h2>
  <?= $body ?>
</div>
