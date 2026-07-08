<?php
declare(strict_types=1);

/**
 * Rendu des sections « workflow » et « data » de la page Détail soumission.
 *
 * Extrait dans un fichier dédié pour garder lib/render_submission_view.php
 * sous 600 lignes (refactor « all-under-600 »). Comportement strictement
 * identique au rendu historique de submission_view.php.
 *
 *  - render_submission_view_workflow_diagram()    : circuit de validation visuel
 *  - render_submission_view_workflow_actions()    : actions admin (rappeler / régénérer)
 *  - render_submission_view_delegation_form()     : formulaire de délégation
 *  - render_submission_view_form_data()           : données saisies du formulaire
 *  - render_submission_view_validator_data()      : données saisies par les validateurs
 *  - render_submission_view_validation_history()  : historique des validations
 *  - render_submission_view_remind_history()      : historique des relances + remind_all
 *  - render_submission_view_attachments()         : pièces jointes
 *
 * @package lib
 * @see /submission_view.php
 * @see /lib/render_submission_view.php
 */

// ── DIAGRAMME WORKFLOW ────────────────────────────────────────

/**
 * Diagramme visuel du circuit de validation (étapes + validateurs).
 *
 * @param array<int, array<string, mixed>> $workflow_steps Étapes (avec step_status, tokens)
 * @param string                           $status         Statut soumission
 */
function render_submission_view_workflow_diagram(array $workflow_steps, string $status): string
{
    $steps_html = '';
    foreach ($workflow_steps as $i => $ws) {
        $step_cls = (string)($ws['step_status'] ?? 'upcoming');
        // Si la soumission est refusee, les étapes en cours deviennent "refused"
        if ($status === 'refuse' && ($ws['step_status'] ?? '') === 'current') {
            $step_cls = 'refused';
        }

        $connector = $i > 0 ? '<div class="wf-connector"><span class="arrow">→</span></div>' : '';

        $ordre      = (int)($ws['ordre'] ?? 0);
        $step_label = h((string)($ws['step_label'] ?? ''));
        $tokens     = $ws['tokens'] ?? [];

        $validators_html = '';
        if (!empty($tokens)) {
            foreach ($tokens as $tok) {
                $email        = display_user((string)($tok['email'] ?? ''));  // v10.0.2 — display_user au lieu de h()
                $relance      = (int)($tok['relance_count'] ?? 0);
                $done         = !empty($tok['done_at']);
                $is_current   = ($ws['step_status'] ?? '') === 'current';

                if ($done) {
                    $icon = '<span class="wf-check" aria-hidden="true">✓</span>';
                } elseif ($is_current) {
                    $icon = '<span class="wf-pending" aria-hidden="true">⏳</span>';
                } else {
                    $icon = '<span class="wf-waiting" aria-hidden="true">○</span>';
                }

                $relance_html = '';
                if ($relance > 0 && !$done) {
                    $sfx   = $relance > 1 ? 's' : '';
                    $relance_html = "<span style=\"color:#b45309;font-size:.7rem;margin-left:.25rem;\">({$relance} rappel{$sfx})</span>";
                }

                $validators_html .= <<<HTML
                  <div class="wf-validator-item">
                    {$icon}
                    <span>{$email}</span>
                    {$relance_html}
                  </div>
HTML;
            }
        } else {
            $validators_html = '<span class="wf-waiting">En attente de démarrage</span>';
        }

        $steps_html .= <<<HTML
          {$connector}
          <div class="wf-step {$step_cls}">
            <div class="wf-ordre">Étape {$ordre}</div>
            <div class="wf-label">{$step_label}</div>
            <div class="wf-validators">
              {$validators_html}
            </div>
          </div>
HTML;
    }

    return <<<HTML
  <!-- Workflow diagram -->
  <div class="card">
    <h2><span aria-hidden="true">🔀</span> Circuit de validation</h2>
    <div class="workflow-diagram">
      <div class="wf-flow">
        {$steps_html}
      </div>
    </div>
HTML;
}

// ── ACTIONS ADMIN (rappeler / régénérer) ──────────────────────

/**
 * Barre d'actions admin : boutons « Rappeler » et « Régénérer » pour chaque
 * token en attente. Affiché uniquement si admin + soumission en cours.
 *
 * @param array<int, array<string, mixed>> $all_tokens Tokens de la soumission
 * @param bool                             $is_admin   Utilisateur admin ?
 * @param string                           $status     Statut soumission
 */
function render_submission_view_workflow_actions(array $all_tokens, bool $is_admin, string $status): string
{
    if (!$is_admin || $status !== 'en_cours') {
        return '';
    }

    $forms_html = '';
    foreach ($all_tokens as $tok) {
        if (!empty($tok['done_at'])) {
            continue;
        }
        $tok_id  = h((string)($tok['id'] ?? ''));
        $email   = display_user((string)($tok['email'] ?? ''));  // v10.0.2 — display_user
        $csrf    = \App\Core\App::security()->csrfField();

        $forms_html .= <<<HTML
          <form method="POST" style="display:inline;">
            {$csrf}
            <input type="hidden" name="action" value="remind_one">
            <input type="hidden" name="token_id" value="{$tok_id}">
            <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;"><span aria-hidden="true">📧</span> Rappeler {$email}</button>
          </form>
          <form method="POST" style="display:inline;">
            {$csrf}
            <input type="hidden" name="action" value="regenerate_token">
            <input type="hidden" name="token_id" value="{$tok_id}">
            <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;"><span aria-hidden="true">🔄</span> Régénérer {$email}</button>
          </form>
HTML;
    }

    if ($forms_html === '') {
        return '';
    }

    return <<<HTML
    <div class="actions-bar">
      {$forms_html}
    </div>
HTML;
}

// ── FORMULAIRE DE DÉLÉGATION ──────────────────────────────────

/**
 * Formulaire de délégation de validation. Affiché si soumission en cours
 * et utilisateur a des tokens en attente (admin ou validateur assigné).
 *
 * @param array<int, array<string, mixed>> $all_tokens Tokens de la soumission
 * @param string                           $user       Email utilisateur courant
 * @param bool                             $is_admin   Utilisateur admin ?
 * @param string                           $status     Statut soumission
 */
function render_submission_view_delegation_form(array $all_tokens, string $user, bool $is_admin, string $status): string
{
    if ($status !== 'en_cours') {
        return '';
    }

    $my_pending = array_filter($all_tokens, function ($tok) use ($user, $is_admin) {
        return empty($tok['done_at']) && ($is_admin || $tok['email'] === $user);
    });

    if (empty($my_pending)) {
        return '';
    }

    $options_html = '';
    foreach ($my_pending as $mpt) {
        $id    = h((string)($mpt['id'] ?? ''));
        $ordre = (int)($mpt['ordre'] ?? 0);
        $email = display_user((string)($mpt['email'] ?? ''));  // v10.0.2 — display_user
        $options_html .= "<option value=\"{$id}\">Étape {$ordre} — {$email}</option>";
    }

    $csrf = \App\Core\App::security()->csrfField();

    return <<<HTML
    <div class="actions-bar" style="margin-top:0;">
      <strong style="font-size:.85rem;color:#003189;"><span aria-hidden="true">🔄</span> Déléguer ma validation :</strong>
      <form method="POST" style="display:inline-flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        {$csrf}
        <input type="hidden" name="action" value="delegate_token">
        <select name="token_id" style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;">
          {$options_html}
        </select>
        <input type="email" name="delegate_to" placeholder="email@dreets.gouv.fr" required style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;width:220px;">
        <input type="text" name="delegate_reason" placeholder="Motif (optionnel)" style="padding:.3rem .5rem;font-size:.8rem;border:1px solid #aaa;border-radius:3px;width:180px;">
        <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;background:#6c3483;color:#fff;"><span aria-hidden="true">🔄</span> Déléguer</button>
      </form>
    </div>
HTML;
}

// ── DONNÉES DU FORMULAIRE ─────────────────────────────────────

/**
 * Carte données saisies du formulaire, regroupées par card_group.
 *
 * @param array<string, mixed>                  $data       Données JSON décodées
 * @param array<string, array<string, mixed>>   $field_info Infos champs (field_name => row)
 */
function render_submission_view_form_data(array $data, array $field_info): string
{
    $items_html = '';
    $current_group = '';
    foreach ($data as $k => $v) {
        if ($k === 'validations') {
            continue;
        }
        if (empty($v) && $v !== '0') {
            continue;
        }

        $group = isset($field_info[$k]) ? $field_info[$k]['card_group'] : '';
        $label = isset($field_info[$k])
            ? $field_info[$k]['label']
            : ucfirst(is_string($k) ? str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k)) : '');
        $display_val = $v === '1' ? '✓ Oui' : ($v === '0' ? 'Non' : h((string)$v));

        if ($group !== $current_group && !empty($group)) {
            $current_group = $group;
            $group_h = h($group);
            $items_html .= <<<HTML
        <div class="data-group-title">{$group_h}</div>
HTML;
        }

        $label_h = h((string)$label);
        $items_html .= <<<HTML
        <div class="data-item">
          <div class="data-label">{$label_h}</div>
          <div class="data-value">{$display_val}</div>
        </div>
HTML;
    }

    return <<<HTML
  <!-- Données du formulaire -->
  <div class="card">
    <h2><span aria-hidden="true">📋</span> Données du formulaire</h2>
    <div class="data-grid">
      {$items_html}
    </div>
  </div>
HTML;
}

// ── DONNÉES DES VALIDATEURS ───────────────────────────────────

/**
 * Carte données saisies par les validateurs (filled_by='validator') — Option A.
 * Affichée uniquement si des données validateur existent.
 *
 * OWNER-EDIT : si $can_edit est vrai (admin ou owner du formulaire), chaque
 * champ est rendu dans un mini-formulaire inline permettant de modifier la
 * valeur (input text + bouton « Modifier »). Sinon, affichage lecture seule
 * (comportement historique). Le conteneur porte l'ancre id="validator-data"
 * pour le PRG du POST handler de submission_view.php.
 *
 * @param array<int, array<string, mixed>>    $validator_data_rows Lignes validator_data
 * @param array<string, array<string, mixed>> $field_info          Infos champs (field_name => row)
 * @param bool                                 $can_edit            true si admin/owner (formulaire d'édition)
 * @param string                               $sub_id              ID soumission (pour le POST)
 */
function render_submission_view_validator_data(array $validator_data_rows, array $field_info, bool $can_edit = false, string $sub_id = ''): string
{
    if (empty($validator_data_rows)) {
        return '';
    }

    $items_html = '';
    foreach ($validator_data_rows as $vr) {
        $field_name = (string)($vr['field_name'] ?? '');
        $label = isset($field_info[$field_name])
            ? t_jargon($field_info[$field_name]['label'])
            : t_jargon((string)($vr['field_label'] ?? $field_name));
        $label_h = h($label);
        $value_raw = (string)($vr['value'] ?? '');
        $display_val = h($value_raw);

        // P2-C : enrichir l'affichage avec step_label + filled_by_email + filled_at.
        // On construit la ligne d'audit en échapant chaque partie une seule fois
        // (le brief d'origine proposait h($by) après avoir déjà échapé $by, ce
        // qui double-escape — on évite ce piège en construisant des chaînes
        // brutes puis en appelant h() au moment de la concaténation).
        $by_email  = isset($vr['filled_by_email']) ? (string)$vr['filled_by_email'] : '';
        $step_lab  = isset($vr['step_label']) ? (string)$vr['step_label'] : '';
        $filled_at = isset($vr['filled_at']) ? (string)$vr['filled_at'] : '';

        $audit_parts   = ['Rempli'];
        if ($by_email !== '') {
            $audit_parts[] = ' par ' . display_user($by_email);
        }
        if ($step_lab !== '') {
            $audit_parts[] = ' — étape : ' . h(t_jargon($step_lab));
        }
        if ($filled_at !== '') {
            $ts = strtotime($filled_at);
            if ($ts !== false) {
                $audit_parts[] = ' le ' . h(date('d/m/Y à H:i', $ts));
            }
        }
        $audit_line = implode('', $audit_parts);

        // OWNER-EDIT : bloc valeur — formulaire inline éditable ou lecture seule.
        if ($can_edit) {
            $csrf         = \App\Core\App::security()->csrfField();
            $sub_id_h     = h($sub_id);
            $fname_h      = h($field_name);
            $value_input  = h($value_raw);
            $value_block = <<<HTML
          <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.25rem;">
            {$csrf}
            <input type="hidden" name="action" value="update_validator_field">
            <input type="hidden" name="sub_id" value="{$sub_id_h}">
            <input type="hidden" name="field_name" value="{$fname_h}">
            <input type="text" name="value" value="{$value_input}" style="flex:1;min-width:200px;padding:.3rem .5rem;font-size:.85rem;border:1px solid #aaa;border-radius:3px;" aria-label="Valeur du champ">
            <button type="submit" class="btn btn-secondary" style="font-size:.75rem;padding:.2rem .5rem;"><span aria-hidden="true">✏️</span> Modifier</button>
          </form>
HTML;
        } else {
            $value_block = <<<HTML
          <div class="data-value">{$display_val}</div>
HTML;
        }

        $items_html .= <<<HTML
        <div class="data-item" style="grid-column: 1 / -1; background: var(--c-primary-50); border-radius: var(--r-sm); padding: .75rem 1rem;">
          <div class="data-label">{$label_h}</div>
          {$value_block}
          <div style="font-size: .75rem; color: #888; margin-top: .25rem;">{$audit_line}</div>
        </div>
HTML;
    }

    // Indicateur visuel si l'édition est activée — facilite la compréhension
    // pour l'utilisateur (champs modifiables post-validation).
    $edit_hint = $can_edit
        ? '<p class="hint" style="margin-bottom: 1rem;">Informations saisies par les validateurs au cours du circuit. <strong>Vous pouvez modifier ces champs.</strong></p>'
        : '<p class="hint" style="margin-bottom: 1rem;">Informations saisies par les validateurs au cours du circuit.</p>';

    return <<<HTML
  <!-- Données des validateurs (filled_by='validator') — Option A -->
  <div class="card" id="validator-data" style="border-left: 4px solid var(--c-primary);">
    <h2><span aria-hidden="true">🛡️</span> Données des validateurs</h2>
    {$edit_hint}
    <div class="data-grid">
      {$items_html}
    </div>
  </div>
HTML;
}

// ── HISTORIQUE DES VALIDATIONS ────────────────────────────────

/**
 * Carte historique des validations (validations stockées dans $data['validations']).
 *
 * @param array<string, mixed> $data Données JSON décodées (clé 'validations')
 */
function render_submission_view_validation_history(array $data): string
{
    if (!isset($data['validations']) || !is_array($data['validations']) || empty($data['validations'])) {
        return '';
    }

    // v9.8.0 — Refonte globale : utilisation de display_user() centralisée
    // v10.0.2 — Affichage des 2 infos : email du token (destinataire notif,
    //   shared mailbox possible) + done_by (user logged-on qui a cliqué)
    $items_html = '';
    foreach ($data['validations'] as $v) {
        $is_valid = ($v['action'] ?? '') === 'valider';
        $icon = $is_valid ? '✅' : '❌';
        $step_label = h((string)($v['step_label'] ?? ''));
        $email_display = display_user((string)($v['email'] ?? ''));
        $color = $is_valid ? '#1a6b3c' : '#c0392b';
        $action_label = $is_valid ? 'Validé' : 'Refusé';
        $date = h((string)($v['date'] ?? ''));

        // v10.0.2 — Afficher "par X" si done_by est renseigné et différent de l'email du token
        // (cas shared mailbox : email = mailbox, done_by = personne physique)
        $done_by_html = '';
        $done_by = (string)($v['done_by'] ?? '');
        if ($done_by !== '' && strcasecmp($done_by, (string)($v['email'] ?? '')) !== 0) {
            $done_by_display = display_user($done_by);
            $done_by_html = <<<HTML
          <div class="val-done-by"><span aria-hidden="true">👤</span> Action effectuée par : {$done_by_display}</div>
HTML;
        }

        $comment_html = '';
        if (!empty($v['commentaire'])) {
            $comment = h((string)$v['commentaire']);
            $comment_html = <<<HTML
          <div class="val-comment"><span aria-hidden="true">💬</span> {$comment}</div>
HTML;
        }

        $items_html .= <<<HTML
    <div class="val-item">
      <div class="val-icon"><span aria-hidden="true">{$icon}</span></div>
      <div class="val-content">
        <div class="val-header">
          {$step_label} — {$email_display}
          <span style="color:{$color};">
            {$action_label}
          </span>
        </div>
        <div class="val-detail">{$date}</div>
        {$done_by_html}
        {$comment_html}
      </div>
    </div>
HTML;
    }

    return <<<HTML
  <!-- Historique des validations -->
  <div class="card">
    <h2><span aria-hidden="true">📝</span> Historique des validations</h2>
    {$items_html}
  </div>
HTML;
}

// ── HISTORIQUE DES RELANCES ───────────────────────────────────

/**
 * Carte historique des relances : validateurs en attente + détail des
 * relances envoyées + action « Rappeler tous » (admin).
 *
 * @param array<int, array<string, mixed>> $all_tokens            Tous les tokens
 * @param array<int, array<string, mixed>> $submission_reminds   Entrées audit_log (manual_remind/auto_remind)
 * @param int                              $total_relances        Total des relances
 * @param array<int, array<string, mixed>> $pending_with_relance  Tokens en attente avec relance
 * @param bool                             $is_admin              Utilisateur admin ?
 * @param string                           $status                Statut soumission
 */
function render_submission_view_remind_history(array $all_tokens, array $submission_reminds, int $total_relances, array $pending_with_relance, bool $is_admin, string $status): string
{
    // v10.1.5 — Refonte : affiche TOUJOURS la section (même si 0 relance)
    // avec pour chaque token : email, date notification initiale, date dernière
    // relance, date prochaine relance automatique.
    // Renommé "Historique des relances" → "Notifications envoyées".

    // ── Validateurs en attente (ou avec relances) ──
    $pending_html = '';
    if (!empty($pending_with_relance) || ($status === 'en_cours' && !empty($all_tokens))) {
        // Filtrer les tokens en attente seulement
        $pending_tokens = array_filter($all_tokens, function ($t) {
            return empty($t['done_at']);
        });

        if (!empty($pending_tokens)) {
            $rows = '';
            foreach ($pending_tokens as $pt) {
                $email_display = display_user((string)($pt['email'] ?? ''));
                $relance = (int)($pt['relance_count'] ?? 0);

                // Date de notification initiale (sent_at)
                $sent_html = '';
                if (!empty($pt['sent_at'])) {
                    $sent_date = h(date('d/m/Y à H:i', strtotime((string)$pt['sent_at'])));
                    $sent_html = "<span style=\"font-size:.8rem;color:#595959;\">Notifié le : {$sent_date}</span>";
                }

                // Date dernière relance (relance_at)
                $last_remind = '';
                if (!empty($pt['relance_at'])) {
                    $last_remind_date = h(date('d/m/Y à H:i', strtotime((string)$pt['relance_at'])));
                    $last_remind = "<span style=\"font-size:.8rem;color:#b45309;\">Dernière relance : {$last_remind_date}</span>";
                }

                // Date d'expiration (prochaine relance auto = expires_at, ou calculée)
                $expires_html = '';
                if (!empty($pt['expires_at'])) {
                    $expires_date = h(date('d/m/Y', strtotime((string)$pt['expires_at'])));
                    $expires_html = "<span style=\"font-size:.8rem;color:#595959;\">Expire le : {$expires_date}</span>";
                }

                // Compteur de relances
                $relance_badge = '';
                if ($relance > 0) {
                    $sfx = $relance > 1 ? 's' : '';
                    $relance_badge = "<span class=\"badge badge-warn\">{$relance} relance{$sfx}</span>";
                }

                $rows .= <<<HTML
          <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem 0;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;">
            <span style="font-size:1.1rem;" aria-hidden="true">⏳</span>
            <strong style="font-size:.85rem;">{$email_display}</strong>
            {$relance_badge}
            {$sent_html}
            {$last_remind}
            {$expires_html}
          </div>
HTML;
            }
            $pending_html = <<<HTML
      <div style="margin-bottom:1rem;">
        {$rows}
      </div>
HTML;
        }
    }

    // ── Détail des relances envoyées (audit_log) ──
    $detail_html = '';
    if (!empty($submission_reminds)) {
        $rows = '';
        foreach ($submission_reminds as $sr) {
            $detail = h((string)($sr['detail'] ?? ''));
            $date   = h(date('d/m/Y à H:i', strtotime((string)($sr['created_at'] ?? 'now'))));
            $actor  = display_user((string)($sr['actor'] ?? ''));
            $rows .= <<<HTML
      <div class="val-item">
        <div class="val-icon" aria-hidden="true">🔔</div>
        <div class="val-content">
          <div class="val-header">{$detail}</div>
          <div class="val-detail">{$date} — par {$actor}</div>
        </div>
      </div>
HTML;
        }
        $detail_html = <<<HTML
      <h3 style="font-size:.9rem;color:#555;margin-bottom:.75rem;">Détail des notifications envoyées</h3>
      {$rows}
HTML;
    }

    // ── Action « Rappeler tous » (admin) ──
    $action_html = '';
    if ($is_admin && $status === 'en_cours') {
        $csrf = \App\Core\App::security()->csrfField();
        $action_html = <<<HTML
    <div class="actions-bar">
      <form method="POST">
        {$csrf}
        <input type="hidden" name="action" value="remind_all">
        <button type="submit" class="btn btn-secondary" style="font-size:.85rem;"><span aria-hidden="true">📧</span> Rappeler tous les validateurs en attente</button>
      </form>
    </div>
HTML;
    }

    return <<<HTML
  <!-- v10.1.5 — "Historique des relances" → "Notifications envoyées" -->
  <div class="card">
    <h2><span aria-hidden="true">🔔</span> Notifications envoyées</h2>
    {$pending_html}
    {$detail_html}
    {$action_html}
  </div>
HTML;
}

// ── PIÈCES JOINTES ────────────────────────────────────────────

/**
 * Carte pièces jointes. Affichée uniquement si des pièces existent.
 *
 * @param array<int, array<string, mixed>> $attachments Pièces jointes
 */
function render_submission_view_attachments(array $attachments): string
{
    if (empty($attachments)) {
        return '';
    }

    $count = count($attachments);
    $rows = '';
    foreach ($attachments as $att) {
        $icon         = get_file_icon((string)($att['mime_type'] ?? ''));
        $name         = h((string)($att['original_name'] ?? ''));
        $mime         = h((string)($att['mime_type'] ?? ''));
        $size         = format_file_size((int)($att['file_size'] ?? 0));
        $date         = h(date('d/m/Y H:i', strtotime((string)($att['uploaded_at'] ?? 'now'))));
        $dl_url       = h('index.php?p=download&id=' . urlencode((string)($att['id'] ?? '')));

        $rows .= <<<HTML
        <tr>
          <td style="padding:.5rem;border-bottom:1px solid #eee;">
            {$icon}
            <strong>{$name}</strong>
          </td>
          <td style="padding:.5rem;border-bottom:1px solid #eee;font-size:.85rem;color:#595959;">{$mime}</td>
          <td style="padding:.5rem;border-bottom:1px solid #eee;font-size:.85rem;">{$size}</td>
          <td style="padding:.5rem;border-bottom:1px solid #eee;font-size:.85rem;">{$date}</td>
          <td style="padding:.5rem;border-bottom:1px solid #eee;text-align:right;">
            <a href="{$dl_url}" class="btn btn-secondary" style="font-size:.75rem;padding:.25rem .6rem;text-decoration:none;"><span aria-hidden="true">📥</span> Télécharger</a>
          </td>
        </tr>
HTML;
    }

    return <<<HTML
  <!-- Pièces jointes -->
  <div class="card">
    <h2><span aria-hidden="true">📎</span> Pièces jointes ({$count})</h2>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr>
          <th style="text-align:left;padding:.5rem;border-bottom:2px solid #003189;">Fichier</th>
          <th style="text-align:left;padding:.5rem;border-bottom:2px solid #003189;">Type</th>
          <th style="text-align:left;padding:.5rem;border-bottom:2px solid #003189;">Taille</th>
          <th style="text-align:left;padding:.5rem;border-bottom:2px solid #003189;">Date</th>
          <th style="text-align:right;padding:.5rem;border-bottom:2px solid #003189;"></th>
        </tr>
      </thead>
      <tbody>
      {$rows}
      </tbody>
    </table>
  </div>
HTML;
}
