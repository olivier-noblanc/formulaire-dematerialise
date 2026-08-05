<?php
$remind_html = '';
if ($last_remind !== '' && $last_remind !== '0') {
    $remind_ts  = strtotime((string) $last_remind);
    $remind_age = ($remind_ts !== false) ? (time() - $remind_ts) : 999999;
    $remind_ok  = $remind_age < 86400;
    $remind_dot_cls = $remind_ok ? 'health-ok' : 'health-warn';
    $remind_date    = \App\Core\App::html()->escape(date('d/m/Y à H:i', $remind_ts !== false ? $remind_ts : 0));
    $remind_badge   = $remind_ok
        ? '<br><span class="badge badge-ok mt-25"><span aria-hidden="true">✓</span> Actif</span>'
        : '<br><span class="badge badge-warn mt-25"><span aria-hidden="true">⚠</span> Il y a plus de 24h</span>';
    $remind_html = <<<HTML
                  <span class="health-dot {$remind_dot_cls} mt-5"></span>
                  Dernière exécution : <strong>{$remind_date}</strong>
                  {$remind_badge}
    HTML;
} else {
    $remind_html = '<span class="health-dot health-unknown"></span><span class="badge badge-info">Jamais exécuté</span>';
}

$alert_html = '';
if ($last_alert_check !== '' && $last_alert_check !== '0') {
    $alert_ts  = strtotime((string) $last_alert_check);
    $alert_age = ($alert_ts !== false) ? (time() - $alert_ts) : 999999;
    $alert_ok  = $alert_age < 86400;
    $alert_dot_cls = $alert_ok ? 'health-ok' : 'health-warn';
    $alert_date    = \App\Core\App::html()->escape(date('d/m/Y à H:i', $alert_ts !== false ? $alert_ts : 0));
    $alert_badge   = $alert_ok
        ? '<br><span class="badge badge-ok mt-25"><span aria-hidden="true">✓</span> Actif</span>'
        : '<br><span class="badge badge-warn mt-25"><span aria-hidden="true">⚠</span> Il y a plus de 24h</span>';
    $alert_html = <<<HTML
                  <span class="health-dot {$alert_dot_cls} mt-5"></span>
                  Dernière exécution : <strong>{$alert_date}</strong>
                  {$alert_badge}
    HTML;
} else {
    $alert_html = '<span class="health-dot health-unknown"></span><span class="badge badge-info">Jamais exécuté</span>';
}

$delai_relance    = \App\Core\App::html()->escape(\App\Core\App::settings()->get('delai_relance_h', '48'));
$relance_max      = \App\Core\App::html()->escape(\App\Core\App::settings()->get('relance_max', '3'));
$token_expire_days = \App\Core\App::html()->escape(\App\Core\App::settings()->get('token_expire_days', '30'));
?>
<!-- Scripts automatises -->
<div class="card">
  <h2><span aria-hidden="true">🤖</span> Scripts automatisés</h2>
  <!-- Script de relance -->
  <div class="u-bor-mar-pad-2">
    <strong class="u-fon-4"><span aria-hidden="true">🔄</span> Script de relance (remind.php)</strong><br>
    <?= $remind_html ?>
  </div>
  <!-- Script d'alerte -->
  <div>
    <strong class="u-fon-4"><span aria-hidden="true">🔔</span> Script d'alerte (alert_check.php)</strong><br>
    <?= $alert_html ?>
    <p class="hint-text-3">
      Délai relance : <strong><?= $delai_relance ?>h</strong> |
      Max relances : <strong><?= $relance_max ?></strong> |
      Expiration tokens : <strong><?= $token_expire_days ?>j</strong>
    </p>
  </div>
</div>
