<?php
$smtp_host    = \App\Core\App::html()->escape(\App\Core\App::settings()->get('smtp_host'));
$smtp_port    = \App\Core\App::html()->escape(\App\Core\App::settings()->get('smtp_port'));
$smtp_secure_val = \App\Core\App::settings()->get('smtp_secure', '');
$smtp_secure  = \App\Core\App::html()->escape($smtp_secure_val !== '' ? $smtp_secure_val : 'Aucun');
$mail_dry_run = \App\Core\App::settings()->get('mail_dry_run', '0') === '1';

if ($smtp_status === 'ok') {
    $dot         = '<span class="health-dot health-ok"></span>';
    $badge       = '<span class="badge badge-ok">Fonctionnel</span>';
    $detail_html = \App\Core\App::html()->escape($smtp_detail);
} elseif ($smtp_status === 'erreur') {
    $dot         = '<span class="health-dot health-err"></span>';
    $badge       = '<span class="badge badge-err">Erreur</span>';
    $detail_html = \App\Core\App::html()->escape($smtp_detail);
} else {
    $dot         = '<span class="health-dot health-unknown"></span>';
    $badge       = '<span class="badge badge-info">Non testé</span>';
    $detail_html = 'Cliquez sur le bouton pour tester la connexion SMTP.';
}

$dryrun_html = '';
if ($mail_dry_run) {
    $dryrun_html = '<div class="warning-box u-fon-mar-2"><strong>⚠ Mode Dry-Run actif</strong> — Aucun email réel n\'est envoyé. Tous les envois sont journalisés mais ne quittent pas le serveur. Désactivez le Dry-Run dans <a href="index.php?p=admin_settings#section-email-verify">Paramètres → Sécurité email</a> pour activer l\'envoi réel.</div>';
}

$debug_html = '';
if ($smtp_debug_log !== '') {
    $debug_html = '<details class="styled-box-8">'
        . '<summary class="u-col-cur-fon-fon-2">📋 Conversation SMTP (debug)</summary>'
        . '<pre class="styled-box-4">' . \App\Core\App::html()->escape($smtp_debug_log) . '</pre>'
        . '</details>';
}
?>
<!-- Santé SMTP -->
<div class="card">
  <h2><span aria-hidden="true">📧</span> Santé SMTP</h2>
  <?= $dryrun_html ?>
  <p class="mb-1">
    <?= $dot ?>
    <?= $badge ?>
    <?= $detail_html ?>
  </p>
  <p class="u-col-fon-mar-3">
    Hôte : <strong><?= $smtp_host ?></strong> |
    Port : <strong><?= $smtp_port ?></strong> |
    Chiffrement : <strong><?= $smtp_secure ?></strong>
  </p>
  <a href="index.php?p=monitoring&test_smtp=1" class="btn btn-primary">Tester SMTP</a>
  <?= $debug_html ?>
</div>
