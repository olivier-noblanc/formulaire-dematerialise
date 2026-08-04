<?php
if ($active_alerts === []) {
    return '';
}

$rows = '';
foreach ($active_alerts as $active_alert) {
    $days = (int) ($active_alert['days_remaining'] ?? 0);
    $row_cls = $days < 0 ? 'urgent' : ($days <= 2 ? 'urgent' : ($days <= 5 ? 'warning' : 'ok'));
    $days_cls = $days < 0 ? 'overdue' : ($days <= 2 ? 'critical' : ($days <= 5 ? 'warning' : 'ok'));
    $days_text = $days < 0 ? 'J+' . abs($days) : ($days === 0 ? 'Jour J' : 'J-' . $days);

    $form_label    = \App\Core\App::html()->escape((string) ($active_alert['form_label'] ?? ''));
    $nom_agent     = \App\Core\App::html()->escape((string) ($active_alert['nom_agent'] ?? ''));
    $deadline_fmt  = \App\Core\App::html()->escape((string) ($active_alert['deadline_formatted'] ?? ''));
    $pending_steps = (int) ($active_alert['pending_steps'] ?? 0);

    $rows .= <<<HTML
                <tr class="alert-row {$row_cls}">
                  <td><span class="days-remaining {$days_cls}">{$days_text}</span></td>
                  <td><strong>{$form_label}</strong></td>
                  <td>{$nom_agent}</td>
                  <td class="u-whi">{$deadline_fmt}</td>
                  <td><span class="days-remaining {$days_cls}">{$days_text}</span></td>
                  <td><span class="badge badge-warn">{$pending_steps} en attente</span></td>
                </tr>
    HTML;
}
?>
<!-- Alertes actives : soumissions proches de la deadline -->
<div class="card">
  <h2><span aria-hidden="true">🔔</span> Alertes actives — Soumissions proches de la date cible</h2>
  <p class="caption-2">
    Les soumissions suivantes sont en cours et approchent ou dépassent leur date cible avec des étapes non complétées.
  </p>
  <table>
    <thead>
      <tr><th>Urgence</th><th>Formulaire</th><th>Agent</th><th>Date cible</th><th>Jours restants</th><th>Étapes en attente</th></tr>
    </thead>
    <tbody>
    <?= $rows ?>
    </tbody>
  </table>
  <p class="mt-1">
    <a href="index.php?p=admin_alerts" class="btn btn-secondary u-fon-2"><span aria-hidden="true">⚙</span> Configurer les règles d'alerte</a>
  </p>
</div>
