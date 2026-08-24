<?php
if ($recent_alerts === []) {
    return '';
}

$rows = '';
foreach ($recent_alerts as $recent_alert) {
    $date      = \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime((string) ($recent_alert['sent_at'] ?? 'now'))));
    $rule_lbl  = \App\Core\App::html()->escape((string) ($recent_alert['rule_label'] ?? 'Règle supprimée'));
    $form_lbl  = \App\Core\App::html()->escape((string) ($recent_alert['form_label'] ?? ''));
    $message   = \App\Core\App::html()->escape((string) ($recent_alert['message'] ?? ''));

    $rows .= <<<HTML
                    <tr>
                      <td class="u-fon-whi">{$date}</td>
                      <td><span class="badge badge-info">{$rule_lbl}</span></td>
                      <td class="u-fon-2">{$form_lbl}</td>
                      <td class="u-fon">{$message}</td>
                    </tr>
        HTML;
}
?>
<!-- Dernieres alertes envoyees -->
<div class="card">
  <h2><span aria-hidden="true">📬</span> Dernières alertes envoyées</h2>
  <table>
    <thead>
      <tr><th>Date</th><th>Règle</th><th>Formulaire</th><th>Message</th></tr>
    </thead>
    <tbody>
    <?= $rows ?>
    </tbody>
  </table>
</div>
