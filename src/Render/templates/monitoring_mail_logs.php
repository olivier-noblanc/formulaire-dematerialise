<?php
if ($mail_logs === []) {
    ?>
<!-- Journal des emails (vide) -->
<div class="card">
  <h2><span aria-hidden="true">📬</span> Journal des emails</h2>
  <p class="empty-state">Aucune tentative d'envoi d'email journalisée pour le moment.
  Cliquez sur « Tester SMTP » ci-dessus pour générer une première entrée.</p>
</div>
    <?php
    return;
}

$rows = '';
foreach ($mail_logs as $mail_log) {
    $created_at = \App\Core\App::html()->escape((string) ($mail_log['created_at'] ?? ''));
    $recipient  = \App\Core\App::html()->escape((string) ($mail_log['recipient'] ?? ''));
    $subject    = \App\Core\App::html()->escape(mb_strimwidth((string) ($mail_log['subject'] ?? ''), 0, 60, '…', 'UTF-8'));
    $status     = (string) ($mail_log['status'] ?? 'unknown');
    $error      = \App\Core\App::html()->escape((string) ($mail_log['error_message'] ?? ''));
    $smtp_log   = (string) ($mail_log['smtp_log'] ?? '');
    $actor      = \App\Core\App::html()->escape((string) ($mail_log['actor'] ?? ''));
    $ip         = \App\Core\App::html()->escape((string) ($mail_log['ip'] ?? ''));

    $status_labels = [
        'sent'         => ['label' => 'Envoyé',     'cls' => 'badge-ok'],
        'error'        => ['label' => 'Échec',      'cls' => 'badge-err'],
        'blocked'      => ['label' => 'Bloqué',     'cls' => 'badge-warn'],
        'dry_run'      => ['label' => 'Dry-run',    'cls' => 'badge-info'],
    ];
    $badge_info = $status_labels[$status] ?? ['label' => $status, 'cls' => 'badge-info'];
    $badge_html = '<span class="badge ' . $badge_info['cls'] . '">' . $badge_info['label'] . '</span>';

    $err_html = $error !== '' ? '<br><span class="u-col-fon-12">' . $error . '</span>' : '';

    $debug_html = '';
    if ($smtp_log !== '') {
        $debug_html = '<details class="mt-4">'
            . '<summary class="u-col-cur-fon">Voir la conversation SMTP</summary>'
            . '<pre class="styled-box-11">' . \App\Core\App::html()->escape($smtp_log) . '</pre>'
            . '</details>';
    }

    $date_fmt = '';
    $ts = strtotime($created_at);
    $date_fmt = $ts !== false ? \App\Core\App::html()->escape(date('d/m/Y H:i:s', $ts)) : $created_at;

    $rows .= <<<HTML
                <tr>
                  <td class="u-fon-whi-2">{$date_fmt}</td>
                  <td class="u-fon-3">{$recipient}</td>
                  <td class="u-fon-3">{$subject}</td>
                  <td>{$badge_html}{$err_html}{$debug_html}</td>
                  <td class="u-col-fon">{$actor}<br><span class="text-muted">{$ip}</span></td>
                </tr>
    HTML;
}
?>
<!-- Journal des emails -->
<div class="card">
  <h2><span aria-hidden="true">📬</span> Journal des emails (20 derniers)</h2>
  <p class="caption-10">
    Toutes les tentatives d'envoi d'email (succès, échecs, blocages) sont journalisées ici.
    Cliquez sur « Voir la conversation SMTP » pour diagnostiquer les erreurs.
  </p>
  <table>
    <thead>
      <tr><th>Date</th><th>Destinataire</th><th>Sujet</th><th>Statut</th><th>Acteur / IP</th></tr>
    </thead>
    <tbody>
    <?= $rows ?>
    </tbody>
  </table>
</div>
