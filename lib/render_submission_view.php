<?php
declare(strict_types=1);

/**
 * Rendu de la page de détail d'une soumission (submission_view.php).
 *
 * Contient les fonctions de rendu HTML principales de la page Détail soumission :
 *  - submission_view_page_css()                       : CSS spécifique (nowdoc statique)
 *  - render_submission_view_back_link()               : lien retour + message d'action
 *  - render_submission_view_header()                  : titre + meta + badge statut
 *  - render_submission_view_progress()                : barre de progression
 *  - render_submission_view_deadline()                : carte date cible (urgence)
 *  - render_submission_view_delegations()             : délégations effectuées
 *  - render_submission_view_actions()                 : carte actions (annuler soumission)
 *  - render_submission_view_content()                 : compose l'ensemble du contenu
 *
 * Les sections workflow + data (workflow_diagram, workflow_actions,
 * delegation_form, form_data, validator_data, validation_history,
 * remind_history, attachments) sont dans lib/render_submission_view_sections.php
 * (extraites pour rester sous 600 lignes).
 *
 * Le fichier submission_view.php garde toute la logique de data fetching / SQL ;
 * ce module ne contient que du rendu HTML pur (aucun accès DB).
 *
 * @package lib
 * @see /submission_view.php
 * @see /lib/render_submission_view_sections.php
 */

// ── CSS SPÉCIFIQUE PAGE SOUMISSION ────────────────────────────

/**
 * CSS propre à la page Détail soumission : header, progress bar, deadline,
 * workflow diagram, data-grid, validation history, actions-bar, responsive.
 *
 * Retourné sous forme de chaîne pour injection dans render_page($page_css).
 * Le contenu CSS est chargé depuis lib/submission_view_page.css (nowdoc statique,
 * sans interpolation PHP) pour éviter de dépasser 600 lignes dans ce fichier.
 * NB : la règle .progress-bar-fill.in-progress contient littéralement
 * « <?= $progress_pct ?> » (non interpolé en nowdoc) — comportement
 * historique préservé à l'identique.
 */
function submission_view_page_css(): string
{
    static $css = null;
    if ($css === null) {
        $css = (string)file_get_contents(__DIR__ . '/submission_view_page.css');
    }
    return $css;
}

// ── LIEN RETOUR + MESSAGE D'ACTION ────────────────────────────

/**
 * Lien retour (vers dashboard ou my_submissions selon admin) + message d'action.
 *
 * @param bool   $is_admin    Utilisateur admin ?
 * @param string $action_msg  Message d'action (déjà échappé à l'origine)
 */
function render_submission_view_back_link(bool $is_admin, string $action_msg): string
{
    $back_link = $is_admin
        ? '<a href="index.php?p=dashboard" class="back-link">← Retour au tableau de bord</a>'
        : '<a href="index.php?p=my_submissions" class="back-link">← Retour à mes demandes</a>';

    $msg_html = '';
    if ($action_msg !== '') {
        $msg_escaped = h($action_msg);
        $msg_html = <<<HTML
  <div class="msg-info" role="status" aria-live="polite">{$msg_escaped}</div>
HTML;
    }

    return $back_link . "\n" . $msg_html;
}

// ── HEADER ────────────────────────────────────────────────────

/**
 * En-tête de la soumission : titre, agent, dates, badge statut.
 *
 * @param array<string, mixed> $sub          Ligne soumission (avec form_label)
 * @param string               $sub_id       ID soumission
 * @param string               $nom_agent    Nom agent (déjà échappé via h())
 * @param string               $status_label Libellé statut
 * @param string               $status_cls   Classe badge statut
 */
function render_submission_view_header(array $sub, string $sub_id, string $nom_agent, string $status_label, string $status_cls): string
{
    $form_label  = h((string)($sub['form_label'] ?? ''));
    $submitted_by = h((string)($sub['submitted_by'] ?? ''));
    $submitted_at = h(date('d/m/Y à H:i', strtotime((string)($sub['submitted_at'] ?? 'now'))));
    $closed_html = '';
    if (!empty($sub['closed_at'])) {
        $closed_at = h(date('d/m/Y à H:i', strtotime((string)$sub['closed_at'])));
        $closed_html = "<br>Clôturé le : <strong>{$closed_at}</strong>";
    }
    $agent_display = $nom_agent !== '' ? $nom_agent : $submitted_by;

    return <<<HTML
  <!-- Header -->
  <div class="sub-header">
    <div>
      <div class="sub-title">Soumission #{$sub_id} — {$form_label}</div>
      <div class="sub-meta">
        Agent : <strong>{$agent_display}</strong><br>
        Soumis le : <strong>{$submitted_at}</strong>
        {$closed_html}
      </div>
    </div>
    <span class="badge {$status_cls}" style="font-size:1rem;padding:.5rem 1.25rem;">{$status_label}</span>
  </div>
HTML;
}

// ── PROGRESSION ───────────────────────────────────────────────

/**
 * Barre de progression + libellé « X / Y étapes validées ».
 *
 * @param int $progress_pct  Pourcentage de progression (0-100)
 * @param int $done_steps    Étapes validées
 * @param int $total_steps   Total étapes
 */
function render_submission_view_progress(int $progress_pct, int $done_steps, int $total_steps): string
{
    $fill_cls = $progress_pct === 100 ? 'complete' : ($progress_pct > 0 ? 'in-progress' : 'not-started');
    $width    = max($progress_pct, 8);

    return <<<HTML
  <!-- Progression -->
  <div class="progress-section">
    <div class="progress-bar-container">
      <div class="progress-bar-fill {$fill_cls}" style="width:{$width}%;">
        {$progress_pct}%
      </div>
    </div>
    <div class="progress-label">{$done_steps} / {$total_steps} étapes validées</div>
  </div>
HTML;
}

// ── DATE CIBLE ────────────────────────────────────────────────

/**
 * Carte date cible (urgence) — affichée uniquement si deadline + en_cours.
 *
 * @param array<string, mixed> $dl_info        Infos urgence (calculate_deadline_urgency)
 * @param int|null             $deadline_ts    Timestamp deadline (ou null)
 * @param int                  $days_remaining  Jours restants
 * @param string               $status         Statut soumission
 */
function render_submission_view_deadline(array $dl_info, ?int $deadline_ts, int $days_remaining, string $status): string
{
    if (!$deadline_ts || $status !== 'en_cours') {
        return '';
    }

    $urgency = (string)($dl_info['urgency'] ?? '');
    $dl_cls  = $urgency === 'overdue' ? 'overdue' : ($urgency === 'critical' ? 'urgent' : 'ok');
    $dl_icon = $urgency === 'overdue' ? '🚨' : ($urgency === 'critical' ? '⚠️' : '📅');

    if ($days_remaining < 0) {
        $dl_text = 'Date dépassée de ' . abs($days_remaining) . ' jour(s)';
    } elseif ($days_remaining === 0) {
        $dl_text = "C'est aujourd'hui !";
    } else {
        $dl_text = "Plus que {$days_remaining} jour(s)";
    }

    $dl_date = h(date('d/m/Y', $deadline_ts));
    $dl_text_h = h($dl_text);

    return <<<HTML
  <!-- Deadline -->
  <div class="deadline-card {$dl_cls}">
    <div class="dl-icon"><span aria-hidden="true">{$dl_icon}</span></div>
    <div class="dl-text">
      <div class="dl-date">Date cible : {$dl_date}</div>
      <div class="dl-remaining {$dl_cls}">{$dl_text_h}</div>
    </div>
  </div>
HTML;
}
// ── SECTIONS WORKFLOW + DATA ──────────────────────────────────
// Les fonctions suivantes sont dans lib/render_submission_view_sections.php
// (extraites pour garder ce fichier sous 600 lignes) :
//  - render_submission_view_workflow_diagram()
//  - render_submission_view_workflow_actions()
//  - render_submission_view_delegation_form()
//  - render_submission_view_form_data()
//  - render_submission_view_validator_data()
//  - render_submission_view_validation_history()
//  - render_submission_view_remind_history()
//  - render_submission_view_attachments()


// ── DÉLÉGATIONS ───────────────────────────────────────────────

/**
 * Carte délégations effectuées. Affichée uniquement si des délégations existent.
 *
 * @param array<int, array<string, mixed>> $delegations Délégations
 */
function render_submission_view_delegations(array $delegations): string
{
    if (empty($delegations)) {
        return '';
    }

    $items_html = '';
    foreach ($delegations as $dlg) {
        $step_label = h((string)($dlg['step_label'] ?? ''));
        $from       = h((string)($dlg['from_email'] ?? ''));
        $to         = h((string)($dlg['to_email'] ?? ''));
        $date       = h(date('d/m/Y à H:i', strtotime((string)($dlg['delegated_at'] ?? 'now'))));

        $reason_html = '';
        if (!empty($dlg['reason'])) {
            $reason = h((string)$dlg['reason']);
            $reason_html = <<<HTML
          <div class="val-comment"><span aria-hidden="true">💬</span> Motif : {$reason}</div>
HTML;
        }

        $items_html .= <<<HTML
    <div class="val-item">
      <div class="val-icon" aria-hidden="true">🔄</div>
      <div class="val-content">
        <div class="val-header">
          {$step_label} : {$from} → {$to}
        </div>
        <div class="val-detail">{$date}</div>
        {$reason_html}
      </div>
    </div>
HTML;
    }

    return <<<HTML
  <!-- Délégations -->
  <div class="card">
    <h2><span aria-hidden="true">🔄</span> Délégations</h2>
    {$items_html}
  </div>
HTML;
}

// ── ACTIONS (annuler soumission) ──────────────────────────────

/**
 * Carte actions (annuler la soumission). Affichée si soumission en cours
 * et utilisateur est admin ou propriétaire.
 *
 * @param string $status       Statut soumission
 * @param bool   $is_admin     Utilisateur admin ?
 * @param string $submitted_by Email de l'agent ayant soumis
 * @param string $user         Email utilisateur courant
 * @param string $sub_id       ID soumission
 */
function render_submission_view_actions(string $status, bool $is_admin, string $submitted_by, string $user, string $sub_id): string
{
    // v10.1.14 — 2 actions possibles :
    //   1. "🗑 Mettre à la corbeille" si status=en_cours et (admin ou propriétaire)
    //   2. "🗑 Supprimer définitivement" si status=annule ET admin seulement
    $actions = [];

    // Action 1 : Mettre à la corbeille (annuler)
    if ($status === 'en_cours' && ($is_admin || $submitted_by === $user)) {
        $cancel_url = h('index.php?p=confirm_action&action=cancel_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
        $actions[] = '<a href="' . $cancel_url . '" class="btn btn-danger" style="text-decoration:none;"><span aria-hidden="true">🗑</span> Mettre à la corbeille</a>';
    }

    // Action 2 : Supprimer définitivement (admin only, status=annule ou refuse)
    if (($status === 'annule' || $status === 'refuse') && $is_admin) {
        $delete_url = h('index.php?p=confirm_action&action=delete_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
        $actions[] = '<a href="' . $delete_url . '" class="btn btn-danger" style="text-decoration:none;background:#c0392b;"><span aria-hidden="true">⚠</span> Supprimer définitivement</a>';
    }

    if (empty($actions)) return '';

    $actions_html = implode('<br><br>', $actions);
    return <<<HTML
  <!-- Actions -->
  <div class="card">
    <h2><span aria-hidden="true">⚙</span> Actions</h2>
    {$actions_html}
  </div>
HTML;
}

// ── COMMENTAIRE ADMIN (BACKLOG) ───────────────────────────────

/**
 * Carte commentaire admin/owner (annotation libre post-soumission).
 *
 * Visible uniquement si l'utilisateur est admin ou propriétaire du formulaire.
 * Sinon : chaîne vide (pas d'affichage).
 *
 * Si l'utilisateur peut éditer ($can_edit = true) : textarea + bouton Modifier
 * (POST action=update_admin_comment). Sinon : affichage en lecture seule
 * (le commentaire est privé, mais l'admin/owner peut le consulter sans éditer
 * si l'accès lui a été retiré entre-temps — cas rare).
 *
 * L'ancre HTML #admin-comment permet au PRG du POST handler de
 * submission_view.php de repositionner l'utilisateur sur cette section
 * après soumission.
 *
 * @param string $admin_comment Contenu actuel du commentaire (brut, non échappé)
 * @param bool   $can_edit       true si admin/owner (affichage formulaire d'édition)
 * @param string $sub_id         ID soumission (pour le POST handler)
 */
function render_submission_view_admin_comment(string $admin_comment, bool $can_edit, string $sub_id): string
{
    if (!$can_edit) {
        // Affichage privé — les non-admin/owner ne voient pas cette section.
        return '';
    }

    $comment_h   = h((string)$admin_comment);
    $sub_id_h    = h((string)$sub_id);
    $csrf        = \App\Core\App::security()->csrfField();

    // Formulaire inline (textarea + bouton Modifier) — POST update_admin_comment.
    return <<<HTML
  <!-- Commentaire admin -->
  <div class="card" id="admin-comment" style="border-left: 4px solid #b45309;">
    <h2><span aria-hidden="true">💬</span> Commentaire (admin / propriétaire)</h2>
    <p class="hint" style="margin-bottom: 1rem;">Annotation libre post-soumission, indépendante des champs validateur. Visible uniquement par les administrateurs et propriétaires du formulaire.</p>
    <form method="POST" style="display:flex;flex-direction:column;gap:.5rem;">
      {$csrf}
      <input type="hidden" name="action" value="update_admin_comment">
      <input type="hidden" name="sub_id" value="{$sub_id_h}">
      <label for="admin_comment" class="sr-only">Commentaire</label>
      <textarea name="admin_comment" id="admin_comment" rows="4" style="padding:.5rem;font-size:.9rem;border:1px solid #aaa;border-radius:3px;font-family:inherit;" placeholder="Ajouter une note, un suivi, un contexte de clôture...">{$comment_h}</textarea>
      <div>
        <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.4rem .8rem;"><span aria-hidden="true">💾</span> Enregistrer le commentaire</button>
      </div>
    </form>
  </div>
HTML;
}

// ── COMPOSITION DU CONTENU ────────────────────────────────────

/**
 * Compose l'ensemble du contenu HTML de la page Détail soumission.
 *
 * @param array<string, mixed> $ctx Données préparées par submission_view.php :
 *   sub_id, sub, data, status, status_label, status_cls, user, is_admin,
 *   is_form_owner, nom_agent, workflow_steps, all_tokens, total_steps,
 *   done_steps, progress_pct, dl_info, deadline_ts, days_remaining,
 *   action_msg, field_info, validator_data_rows, submission_reminds,
 *   total_relances, pending_with_relance, attachments, delegations,
 *   admin_comment.
 */
function render_submission_view_content(array $ctx): string
{
    $sub_id         = (string)($ctx['sub_id'] ?? '');
    $sub            = $ctx['sub'] ?? [];
    $data           = $ctx['data'] ?? [];
    $status         = (string)($ctx['status'] ?? 'en_cours');
    $status_label   = (string)($ctx['status_label'] ?? '');
    $status_cls     = (string)($ctx['status_cls'] ?? '');
    $user           = (string)($ctx['user'] ?? '');
    $is_admin       = (bool)($ctx['is_admin'] ?? false);
    $is_form_owner  = (bool)($ctx['is_form_owner'] ?? false);
    $nom_agent      = (string)($ctx['nom_agent'] ?? '');
    $workflow_steps = $ctx['workflow_steps'] ?? [];
    $all_tokens     = $ctx['all_tokens'] ?? [];
    $total_steps    = (int)($ctx['total_steps'] ?? 0);
    $done_steps     = (int)($ctx['done_steps'] ?? 0);
    $progress_pct   = (int)($ctx['progress_pct'] ?? 0);
    $dl_info        = $ctx['dl_info'] ?? [];
    $deadline_ts    = $ctx['deadline_ts'] ?? null;
    $days_remaining = (int)($ctx['days_remaining'] ?? 0);
    $action_msg     = (string)($ctx['action_msg'] ?? '');
    $field_info     = $ctx['field_info'] ?? [];
    $validator_rows = $ctx['validator_data_rows'] ?? [];
    $submission_reminds = $ctx['submission_reminds'] ?? [];
    $total_relances     = (int)($ctx['total_relances'] ?? 0);
    $pending_with_relance = $ctx['pending_with_relance'] ?? [];
    $attachments    = $ctx['attachments'] ?? [];
    $delegations    = $ctx['delegations'] ?? [];
    $admin_comment  = (string)($ctx['admin_comment'] ?? '');

    // OWNER-EDIT : l'admin ou l'owner du formulaire peut éditer les champs
    // validateur post-validation (formulaire inline dans la section dédiée).
    $can_edit_validator = $is_admin || $is_form_owner;

    $back_link_html      = render_submission_view_back_link($is_admin, $action_msg);
    $header_html         = render_submission_view_header($sub, $sub_id, $nom_agent, $status_label, $status_cls);
    $progress_html       = render_submission_view_progress($progress_pct, $done_steps, $total_steps);
    $deadline_html       = render_submission_view_deadline($dl_info, $deadline_ts, $days_remaining, $status);
    $workflow_html       = render_submission_view_workflow_diagram($workflow_steps, $status);
    $wf_actions_html     = render_submission_view_workflow_actions($all_tokens, $is_admin, $status);
    $delegation_html     = render_submission_view_delegation_form($all_tokens, $user, $is_admin, $status);
    $form_data_html      = render_submission_view_form_data($data, $field_info);
    $validator_data_html = render_submission_view_validator_data($validator_rows, $field_info, $can_edit_validator, $sub_id);
    $validation_history_html = render_submission_view_validation_history($data);
    $remind_history_html = render_submission_view_remind_history($all_tokens, $submission_reminds, $total_relances, $pending_with_relance, $is_admin, $status);
    $attachments_html    = render_submission_view_attachments($attachments);
    $delegations_html    = render_submission_view_delegations($delegations);
    $actions_html        = render_submission_view_actions($status, $is_admin, (string)($sub['submitted_by'] ?? ''), $user, $sub_id);
    $admin_comment_html  = render_submission_view_admin_comment($admin_comment, $can_edit_validator, $sub_id);

    return <<<HTML

  {$back_link_html}

  {$header_html}

  {$progress_html}

  {$deadline_html}

  {$workflow_html}
    {$wf_actions_html}
    {$delegation_html}
  </div>

  {$form_data_html}

  {$validator_data_html}

  {$validation_history_html}

  {$remind_history_html}

  {$attachments_html}

  {$delegations_html}

  {$admin_comment_html}

  {$actions_html}
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  SECTIONS WORKFLOW + DATA (consolidé depuis render_submission_view_sections.php)
// ═══════════════════════════════════════════════════════════════

// ── DIAGRAMME WORKFLOW ────────────────────────────────────────

function render_submission_view_workflow_diagram(array $workflow_steps, string $status): string
{
    $steps_html = '';
    foreach ($workflow_steps as $i => $ws) {
        $step_cls = (string)($ws['step_status'] ?? 'upcoming');
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
                $email        = \App\Core\App::html()->displayUser((string)($tok['email'] ?? ''));
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
        $email   = \App\Core\App::html()->displayUser((string)($tok['email'] ?? ''));
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
        $email = \App\Core\App::html()->displayUser((string)($mpt['email'] ?? ''));
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
            : ucfirst(is_string($k) ? str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k) ?? $k) : '');
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

        $by_email  = isset($vr['filled_by_email']) ? (string)$vr['filled_by_email'] : '';
        $step_lab  = isset($vr['step_label']) ? (string)$vr['step_label'] : '';
        $filled_at = isset($vr['filled_at']) ? (string)$vr['filled_at'] : '';

        $audit_parts   = ['Rempli'];
        if ($by_email !== '') {
            $audit_parts[] = ' par ' . \App\Core\App::html()->displayUser($by_email);
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

function render_submission_view_validation_history(array $data): string
{
    if (!isset($data['validations']) || !is_array($data['validations']) || empty($data['validations'])) {
        return '';
    }

    $items_html = '';
    foreach ($data['validations'] as $v) {
        $is_valid = ($v['action'] ?? '') === 'valider';
        $icon = $is_valid ? '✅' : '❌';
        $step_label = h((string)($v['step_label'] ?? ''));
        $email_display = \App\Core\App::html()->displayUser((string)($v['email'] ?? ''));
        $color = $is_valid ? '#1a6b3c' : '#c0392b';
        $action_label = $is_valid ? 'Validé' : 'Refusé';
        $date = h((string)($v['date'] ?? ''));

        $done_by_html = '';
        $done_by = (string)($v['done_by'] ?? '');
        if ($done_by !== '' && strcasecmp($done_by, (string)($v['email'] ?? '')) !== 0) {
            $done_by_display = \App\Core\App::html()->displayUser($done_by);
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

function render_submission_view_remind_history(array $all_tokens, array $submission_reminds, int $total_relances, array $pending_with_relance, bool $is_admin, string $status): string
{
    $pending_html = '';
    if (!empty($pending_with_relance) || ($status === 'en_cours' && !empty($all_tokens))) {
        $pending_tokens = array_filter($all_tokens, function ($t) {
            return empty($t['done_at']);
        });

        if (!empty($pending_tokens)) {
            $rows = '';
            foreach ($pending_tokens as $pt) {
                $email_display = \App\Core\App::html()->displayUser((string)($pt['email'] ?? ''));
                $relance = (int)($pt['relance_count'] ?? 0);

                $sent_html = '';
                if (!empty($pt['sent_at'])) {
                    $sent_date = h(date('d/m/Y à H:i', strtotime((string)$pt['sent_at'])));
                    $sent_html = "<span style=\"font-size:.8rem;color:#595959;\">Notifié le : {$sent_date}</span>";
                }

                $last_remind = '';
                if (!empty($pt['relance_at'])) {
                    $last_remind_date = h(date('d/m/Y à H:i', strtotime((string)$pt['relance_at'])));
                    $last_remind = "<span style=\"font-size:.8rem;color:#b45309;\">Dernière relance : {$last_remind_date}</span>";
                }

                $expires_html = '';
                if (!empty($pt['expires_at'])) {
                    $expires_date = h(date('d/m/Y', strtotime((string)$pt['expires_at'])));
                    $expires_html = "<span style=\"font-size:.8rem;color:#595959;\">Expire le : {$expires_date}</span>";
                }

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

    $detail_html = '';
    if (!empty($submission_reminds)) {
        $rows = '';
        foreach ($submission_reminds as $sr) {
            $detail = h((string)($sr['detail'] ?? ''));
            $date   = h(date('d/m/Y à H:i', strtotime((string)($sr['created_at'] ?? 'now'))));
            $actor  = \App\Core\App::html()->displayUser((string)($sr['actor'] ?? ''));
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

function render_submission_view_attachments(array $attachments): string
{
    if (empty($attachments)) {
        return '';
    }

    $count = count($attachments);
    $rows = '';
    foreach ($attachments as $att) {
        $icon         = \App\Core\App::html()->getFileIcon((string)($att['mime_type'] ?? ''));
        $name         = h((string)($att['original_name'] ?? ''));
        $mime         = h((string)($att['mime_type'] ?? ''));
        $size         = \App\Core\App::html()->formatFileSize((int)($att['file_size'] ?? 0));
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
