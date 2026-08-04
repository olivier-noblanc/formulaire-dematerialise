<?php
if ($by_form_stats === [] || (count($by_form_stats) === 1 && (int) $by_form_stats[0]['total'] === 0)) {
    $body = '<p class="empty-state">Aucune soumission enregistrée.</p>';
} else {
    $rows = '';
    foreach ($by_form_stats as $by_form_stat) {
        $bf_total  = (int) $by_form_stat['total'];
        $bf_valide = (int) $by_form_stat['valide'];
        $bf_rate   = $bf_total > 0 ? round(($bf_valide / $bf_total) * 100, 1) : 0;
        $label     = \App\Core\App::html()->escape((string) $by_form_stat['label']);
        $en_cours  = (int) $by_form_stat['en_cours'];
        $refuse    = (int) $by_form_stat['refuse'];

        $rows .= <<<HTML
                  <tr>
                    <td><strong>{$label}</strong></td>
                    <td>{$bf_total}</td>
                    <td><span class="badge badge-warn">{$en_cours}</span></td>
                    <td><span class="badge badge-ok">{$bf_valide}</span></td>
                    <td><span class="badge badge-err">{$refuse}</span></td>
                    <td><strong>{$bf_rate}%</strong></td>
                  </tr>
        HTML;
    }
    $body = <<<HTML
              <table>
                <thead>
                  <tr><th>Formulaire</th><th>Total</th><th>En cours</th><th>Validées</th><th>Refusées</th><th>Taux validation</th></tr>
                </thead>
                <tbody>
                {$rows}
                </tbody>
              </table>
    HTML;
}
?>
<!-- Soumissions par formulaire -->
<div class="card">
  <h2><span aria-hidden="true">📊</span> Soumissions par formulaire</h2>
  <?= $body ?>
</div>
