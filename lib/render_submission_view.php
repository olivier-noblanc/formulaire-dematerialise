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
