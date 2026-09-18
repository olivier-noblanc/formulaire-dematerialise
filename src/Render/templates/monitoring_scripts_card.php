<?php
$remind_html = '';
if ($last_remind !== '' && $last_remind !== '0') {
    // P2-E : last_remind_run est stocké en UTC — parsing UTC explicite pour
    // l'âge, affichage reconverti en Europe/Paris par formatDateTimeFr().
    $remind_ts  = strtotime($last_remind . ' UTC');
    $remind_age = ($remind_ts !== false) ? (time() - $remind_ts) : 999999;
    $remind_ok  = $remind_age < 86400;
    $remind_dot_cls = $remind_ok ? 'health-ok' : 'health-warn';
    $remind_date    = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) $last_remind));
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
    // P2-E : last_alert_check est stocké en UTC — parsing UTC explicite pour
    // l'âge, affichage reconverti en Europe/Paris par formatDateTimeFr().
    $alert_ts  = strtotime($last_alert_check . ' UTC');
    $alert_age = ($alert_ts !== false) ? (time() - $alert_ts) : 999999;
    $alert_ok  = $alert_age < 86400;
    $alert_dot_cls = $alert_ok ? 'health-ok' : 'health-warn';
    $alert_date    = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) $last_alert_check));
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

$token_expire_days = \App\Core\App::html()->escape(\App\Core\App::settings()->get('token_expire_days', '30'));
// Délai de relance et nombre max de relances : configurés par formulaire
// (paramètres de relance de chaque formulaire), plus de réglage global.
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
      Délai et max relances : <strong>par formulaire</strong> |
      Expiration tokens : <strong><?= $token_expire_days ?>j</strong>
    </p>
  </div>
</div>
