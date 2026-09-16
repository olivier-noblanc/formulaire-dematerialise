<?php
// Lane C — notice de rejeu manuel. Défauts sûrs si le template est inclus sans
// les variables (le renderer les fournit toujours) : aucun corps n'est exposé.
$mail_replay_notice ??= '';
$mail_replay_ok ??= false;

$replay_notice_html = '';
if ($mail_replay_notice !== '') {
    $notice_cls = $mail_replay_ok ? 'success-box' : 'warning-box';
    $replay_notice_html = '<div class="' . $notice_cls . ' mb-1">'
        . \App\Core\App::html()->escape($mail_replay_notice)
        . '</div>';
}

if ($mail_logs === []) {
    ?>
<!-- Journal des emails (vide) -->
<div class="card">
  <h2><span aria-hidden="true">📬</span> Journal des emails</h2>
  <?= $replay_notice_html ?>
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
        \App\Enum\MailStatus::Pending->value => ['label' => 'En attente',      'cls' => 'badge-info'],
        'sent'                               => ['label' => 'Envoyé',          'cls' => 'badge-ok'],
        'error'                              => ['label' => 'Échec',           'cls' => 'badge-err'],
        \App\Enum\MailStatus::Failed->value  => ['label' => 'Échec définitif', 'cls' => 'badge-err'],
        'blocked'                            => ['label' => 'Bloqué',          'cls' => 'badge-warn'],
        'dry_run'                            => ['label' => 'Dry-run',         'cls' => 'badge-info'],
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

    // Rejeu manuel : uniquement un email en échec définitif, un message par
    // formulaire POST (pas de rejeu en masse). Le corps n'est jamais transmis.
    $replay_html = '';
    if ($status === \App\Enum\MailStatus::Failed->value) {
        $mail_log_id = \App\Core\App::html()->escape((string) ($mail_log['id'] ?? ''));
        $replay_html = '<form method="POST" class="mt-4">'
            . \App\Core\App::security()->csrfField()
            . '<input type="hidden" name="action" value="mail_replay">'
            . '<input type="hidden" name="mail_log_id" value="' . $mail_log_id . '">'
            . '<button type="submit" class="btn btn-secondary"><span aria-hidden="true">↻</span> Rejouer l\'envoi</button>'
            . '</form>';
    }

    $date_fmt = '';
    // created_at (mail_log) est en UTC (datetime('now')) — interprétation UTC explicite.
    $ts = strtotime($created_at . ' UTC');
    $date_fmt = $ts !== false ? \App\Core\App::html()->escape(date('d/m/Y H:i:s', $ts)) : $created_at;

    $rows .= <<<HTML
                    <tr>
                      <td class="u-fon-whi-2">{$date_fmt}</td>
                      <td class="u-fon-3">{$recipient}</td>
                      <td class="u-fon-3">{$subject}</td>
                      <td>{$badge_html}{$err_html}{$debug_html}{$replay_html}</td>
                      <td class="u-col-fon">{$actor}<br><span class="text-muted">{$ip}</span></td>
                    </tr>
        HTML;
}
?>
<!-- Journal des emails -->
<div class="card">
  <h2><span aria-hidden="true">📬</span> Journal des emails (20 derniers)</h2>
  <?= $replay_notice_html ?>
  <p class="caption-10">
    Toutes les tentatives d'envoi d'email (succès, échecs, blocages) sont journalisées ici.
    Cliquez sur « Voir la conversation SMTP » pour diagnostiquer les erreurs. Un email en
    « Échec définitif » peut être relancé manuellement avec « Rejouer l'envoi ».
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
