<?php
if ($tokens_bloques === []) {
    $body = '<p class="empty-state">Aucun token bloqué — tout est fluide !</p>';
} else {
    $rows = '';
    foreach ($tokens_bloques as $token_bloque) {
        $form_label   = \App\Core\App::html()->escape((string) $token_bloque['form_label']);
        $ordre        = (int) $token_bloque['ordre'];
        $step_label   = \App\Core\App::html()->escape((string) $token_bloque['step_label']);
        $email        = \App\Core\App::html()->escape((string) $token_bloque['email']);
        $sent_at      = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) $token_bloque['sent_at']));
        $relance      = (int) $token_bloque['relance_count'];
        $expires      = $token_bloque['expires_at']
            ? \App\Core\App::html()->escape(date('d/m/Y', (int) strtotime($token_bloque['expires_at'] . ' UTC')))
            : '—';
        $submitted_by = \App\Core\App::html()->escape((string) $token_bloque['submitted_by']);

        $rows .= <<<HTML
                      <tr>
                        <td>{$form_label}</td>
                        <td><span class="badge badge-info">Étape {$ordre} — {$step_label}</span></td>
                        <td>{$email}</td>
                        <td class="u-whi">{$sent_at}</td>
                        <td>{$relance}</td>
                        <td class="u-whi">{$expires}</td>
                        <td>{$submitted_by}</td>
                      </tr>
            HTML;
    }
    $body = <<<HTML
                  <table>
                    <thead>
                      <tr><th>Formulaire</th><th>Étape</th><th>Validateur</th><th>Envoyé le</th><th>Relances</th><th>Expire le</th><th>Agent</th></tr>
                    </thead>
                    <tbody>
                    {$rows}
                    </tbody>
                  </table>
        HTML;
}
?>
<!-- Tokens bloqués -->
<div class="card">
  <h2><span aria-hidden="true">🚨</span> Tokens bloqués (en attente depuis + de <?= $bloque_hours ?>h)</h2>
  <?= $body ?>
</div>
