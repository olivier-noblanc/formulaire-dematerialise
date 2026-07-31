<?php

declare(strict_types=1);

namespace App\Render;

use App\Enum\SubmissionStatus;
use App\Enum\UrgencyLevel;
use App\Enum\ValidationAction;

/**
 * Rendu de la page de détail d'une soumission (submission_view.php).
 *
 * Contient les fonctions de rendu HTML principales de la page Détail soumission :
 *  - pageCss()                       : CSS spécifique (nowdoc statique)
 *  - renderBackLink()               : lien retour + message d'action
 *  - renderHeader()                  : titre + meta + badge statut
 *  - renderProgress()                : barre de progression
 *  - renderDeadline()                : carte date cible (urgence)
 *  - renderDelegations()             : délégations effectuées
 *  - renderActions()                 : carte actions (annuler soumission)
 *  - renderAdminComment()           : commentaire admin/owner
 *  - renderContent()                 : compose l'ensemble du contenu
 *  - renderWorkflowDiagram()         : diagramme workflow
 *  - renderWorkflowActions()         : actions admin (rappeler / régénérer)
 *  - renderDelegationForm()          : formulaire de délégation
 *  - renderFormData()                : données du formulaire
 *  - renderValidatorData()           : données des validateurs
 *  - renderValidationHistory()       : historique des validations
 *  - renderRemindHistory()           : historique des relances
 *  - renderAttachments()             : pièces jointes
 *
 * Le fichier submission_view.php garde toute la logique de data fetching / SQL ;
 * ce module ne contient que du rendu HTML pur (aucun accès DB).
 */
final class SubmissionViewRenderer
{
    /**
     * CSS propre à la page Détail soumission.
     */
    public function pageCss(): string
    {
        static $css = null;
        if ($css === null) {
            $css = (string) file_get_contents(__DIR__ . '/../../lib/submission_view_page.css');
        }
        return $css;
    }

    /**
     * Lien retour (vers dashboard ou my_submissions selon admin) + message d'action.
     */
    public function renderBackLink(bool $is_admin, string $action_msg): string
    {
        $back_link = $is_admin
            ? '<a href="index.php?p=dashboard" class="back-link">← Retour au tableau de bord</a>'
            : '<a href="index.php?p=my_submissions" class="back-link">← Retour à mes demandes</a>';

        $msg_html = '';
        if ($action_msg !== '') {
            $msg_escaped = \App\Core\App::html()->escape($action_msg);
            $msg_html = <<<HTML
                  <div class="msg-info" role="status" aria-live="polite">{$msg_escaped}</div>
                HTML;
        }

        return $back_link . "\n" . $msg_html;
    }

    /**
     * En-tête de la soumission : titre, agent, dates, badge statut.
     *
     * @param array{form_label: string, submitted_by: string, submitted_at: string, closed_at?: string} $sub
     */
    public function renderHeader(array $sub, string $sub_id, string $nom_agent, string $status_label, string $status_cls): string
    {
        $form_label  = \App\Core\App::html()->escape((string) ($sub['form_label'] ?? ''));
        $submitted_by = \App\Core\App::html()->escape((string) ($sub['submitted_by'] ?? ''));
        $submitted_at = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($sub['submitted_at'] ?? 'now'))));
        $closed_html = '';
        if (!empty($sub['closed_at'])) {
            $closed_at = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) $sub['closed_at'])));
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
                <span class="badge {$status_cls} btn-compact">{$status_label}</span>
              </div>
            HTML;
    }

    /**
     * Barre de progression + libellé « X / Y étapes validées ».
     */
    public function renderProgress(int $progress_pct, int $done_steps, int $total_steps): string
    {
        $fill_cls = $progress_pct === 100 ? 'complete' : ($progress_pct > 0 ? 'in-progress' : 'not-started');
        $width    = max($progress_pct, 8);
        $width_cls = 'pw-' . (int) $width;
        \App\Core\App::css()->rule($width_cls, "width:{$width}%;");

        return <<<HTML
              <!-- Progression -->
              <div class="progress-section">
                <div class="progress-bar-container">
                  <div class="progress-bar-fill {$fill_cls} {$width_cls}">
                    {$progress_pct}%
                  </div>
                </div>
                <div class="progress-label">{$done_steps} / {$total_steps} étapes validées</div>
              </div>
            HTML;
    }

    /**
     * Carte date cible (urgence) — affichée uniquement si deadline + en_cours.
     *
     * @param array<string, mixed> $dl_info
     */
    public function renderDeadline(array $dl_info, ?int $deadline_ts, int $days_remaining, string $status): string
    {
        if (!$deadline_ts || $status !== SubmissionStatus::EnCours->value) {
            return '';
        }

        $urgency = (string) ($dl_info['urgency'] ?? '');
        $dl_cls  = $urgency === UrgencyLevel::Overdue->value ? 'overdue' : ($urgency === UrgencyLevel::Critical->value ? 'urgent' : 'ok');
        $dl_icon = $urgency === UrgencyLevel::Overdue->value ? '🚨' : ($urgency === UrgencyLevel::Critical->value ? '⚠️' : '📅');

        if ($days_remaining < 0) {
            $dl_text = 'Date dépassée de ' . abs($days_remaining) . ' jour(s)';
        } elseif ($days_remaining === 0) {
            $dl_text = "C'est aujourd'hui !";
        } else {
            $dl_text = "Plus que {$days_remaining} jour(s)";
        }

        $dl_date = \App\Core\App::html()->escape(date('d/m/Y', $deadline_ts));
        $dl_text_h = \App\Core\App::html()->escape($dl_text);

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

    /**
     * Carte délégations effectuées.
     *
     * @param array<int, array{step_label: string, from_email: string, to_email: string, delegated_at: string, reason?: string}> $delegations
     */
    public function renderDelegations(array $delegations): string
    {
        if ($delegations === []) {
            return '';
        }

        $items_html = '';
        foreach ($delegations as $delegation) {
            $step_label = \App\Core\App::html()->escape((string) ($delegation['step_label'] ?? ''));
            $from       = \App\Core\App::html()->escape((string) ($delegation['from_email'] ?? ''));
            $to         = \App\Core\App::html()->escape((string) ($delegation['to_email'] ?? ''));
            $date       = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($delegation['delegated_at'] ?? 'now'))));

            $reason_html = '';
            if (!empty($delegation['reason'])) {
                $reason = \App\Core\App::html()->escape((string) $delegation['reason']);
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

    /**
     * Carte actions (annuler la soumission).
     */
    public function renderActions(string $status, bool $is_admin, string $submitted_by, string $user, string $sub_id): string
    {
        $actions = [];

        // Action 1 : Mettre à la corbeille (annuler)
        if ($status === SubmissionStatus::EnCours->value && ($is_admin || $submitted_by === $user)) {
            $cancel_url = \App\Core\App::html()->escape('index.php?p=confirm_action&action=cancel_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
            $actions[] = '<a href="' . $cancel_url . '" class="btn btn-danger u-tex"><span aria-hidden="true">🗑</span> Mettre à la corbeille</a>';
        }

        // Action 2 : Supprimer définitivement (admin only, status=annule ou refuse)
        if (($status === SubmissionStatus::Annule->value || $status === SubmissionStatus::Refuse->value) && $is_admin) {
            $delete_url = \App\Core\App::html()->escape('index.php?p=confirm_action&action=delete_submission&submission_id=' . urlencode($sub_id) . '&from=' . urlencode('index.php?p=submission_view&id=' . $sub_id));
            $actions[] = '<a href="' . $delete_url . '" class="btn btn-danger u-bac-tex"><span aria-hidden="true">⚠</span> Supprimer définitivement</a>';
        }

        if ($actions === []) {
            return '';
        }

        $actions_html = implode('<br><br>', $actions);
        return <<<HTML
              <!-- Actions -->
              <div class="card">
                <h2><span aria-hidden="true">⚙</span> Actions</h2>
                {$actions_html}
              </div>
            HTML;
    }

    /**
     * Carte commentaire admin/owner (annotation libre post-soumission).
     */
    public function renderAdminComment(string $admin_comment, bool $can_edit, string $sub_id): string
    {
        if (!$can_edit) {
            return '';
        }

        $comment_h   = \App\Core\App::html()->escape($admin_comment);
        $sub_id_h    = \App\Core\App::html()->escape($sub_id);
        $csrf        = \App\Core\App::security()->csrfField();

        return <<<HTML
              <!-- Commentaire admin -->
              <div class="card u-bor-4" id="admin-comment">
                <h2><span aria-hidden="true">💬</span> Commentaire (admin / propriétaire)</h2>
                <p class="hint mb-1-2">Annotation libre post-soumission, indépendante des champs validateur. Visible uniquement par les administrateurs et propriétaires du formulaire.</p>
                <form method="POST" class="flex-col-gap5">
                  {$csrf}
                  <input type="hidden" name="action" value="update_admin_comment">
                  <input type="hidden" name="sub_id" value="{$sub_id_h}">
                  <label for="admin_comment" class="sr-only">Commentaire</label>
                  <textarea name="admin_comment" id="admin_comment" rows="4" placeholder="Ajouter une note, un suivi, un contexte de clôture..." class="input-filter">{$comment_h}</textarea>
                  <div>
                    <button type="submit" class="btn btn-secondary btn-sm-11"><span aria-hidden="true">💾</span> Enregistrer le commentaire</button>
                  </div>
                </form>
              </div>
            HTML;
    }

    /**
     * Compose l'ensemble du contenu HTML de la page Détail soumission.
     */
    public function renderContent(SubmissionViewContext $ctx): string
    {
        $sub_id         = $ctx->sub_id;
        $sub            = $ctx->sub;
        $data           = $ctx->data;
        $status         = $ctx->status;
        $status_label   = $ctx->status_label;
        $status_cls     = $ctx->status_cls;
        $user           = $ctx->user;
        $is_admin       = $ctx->is_admin;
        $is_form_owner  = $ctx->is_form_owner;
        $nom_agent      = $ctx->nom_agent;
        $workflow_steps = $ctx->workflow_steps;
        $all_tokens     = $ctx->all_tokens;
        $total_steps    = $ctx->total_steps;
        $done_steps     = $ctx->done_steps;
        $progress_pct   = $ctx->progress_pct;
        $dl_info        = $ctx->dl_info;
        $deadline_ts    = $ctx->deadline_ts;
        $days_remaining = $ctx->days_remaining;
        $action_msg     = $ctx->action_msg;
        $field_info     = $ctx->field_info;
        $validator_rows = $ctx->validator_data_rows;
        $submission_reminds = $ctx->submission_reminds;
        $total_relances     = $ctx->total_relances;
        $pending_with_relance = $ctx->pending_with_relance;
        $attachments    = $ctx->attachments;
        $delegations    = $ctx->delegations;
        $admin_comment  = $ctx->admin_comment;

        $can_edit_validator = $is_admin || $is_form_owner;

        $back_link_html      = $this->renderBackLink($is_admin, $action_msg);
        $header_html         = $this->renderHeader($sub, $sub_id, $nom_agent, $status_label, $status_cls);
        $progress_html       = $this->renderProgress($progress_pct, $done_steps, $total_steps);
        $deadline_html       = $this->renderDeadline($dl_info, $deadline_ts, $days_remaining, $status);
        $workflow_html       = $this->renderWorkflowDiagram($workflow_steps, $status);
        $wf_actions_html     = $this->renderWorkflowActions($all_tokens, $is_admin, $status);
        $delegation_html     = $this->renderDelegationForm($all_tokens, $user, $is_admin, $status);
        $form_data_html      = $this->renderFormData($data, $field_info);
        $validator_data_html = $this->renderValidatorData($validator_rows, $field_info, $can_edit_validator, $sub_id);
        $validation_history_html = $this->renderValidationHistory($data);
        $remind_history_html = $this->renderRemindHistory($all_tokens, $submission_reminds, $total_relances, $pending_with_relance, $is_admin, $status);
        $attachments_html    = $this->renderAttachments($attachments);
        $delegations_html    = $this->renderDelegations($delegations);
        $actions_html        = $this->renderActions($status, $is_admin, (string) ($sub['submitted_by'] ?? ''), $user, $sub_id);
        $admin_comment_html  = $this->renderAdminComment($admin_comment, $can_edit_validator, $sub_id);

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
    //  SECTIONS WORKFLOW + DATA
    // ═══════════════════════════════════════════════════════════════

    /**
     * Diagramme workflow — circuit de validation.
     *
     * @param list<array{step_status: string, ordre: int, step_label: string, tokens: list<array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, ordre: int}>}> $workflow_steps
     */
    public function renderWorkflowDiagram(array $workflow_steps, string $status): string
    {
        $steps_html = '';
        foreach ($workflow_steps as $i => $ws) {
            $step_cls = (string) ($ws['step_status'] ?? 'upcoming');
            if ($status === SubmissionStatus::Refuse->value && ($ws['step_status'] ?? '') === 'current') {
                $step_cls = 'refused';
            }

            $connector = $i > 0 ? '<div class="wf-connector"><span class="arrow">→</span></div>' : '';

            $ordre      = (int) ($ws['ordre'] ?? 0);
            $step_label = \App\Core\App::html()->escape((string) ($ws['step_label'] ?? ''));
            $tokens     = $ws['tokens'] ?? [];

            $validators_html = '';
            if ($tokens !== [] && $tokens !== null) {
                foreach ($tokens as $token) {
                    $email        = \App\Core\App::html()->displayUser((string) ($token['email'] ?? ''));
                    $relance      = (int) ($token['relance_count'] ?? 0);
                    $done         = !empty($token['done_at']);
                    $is_current   = ($ws['step_status'] ?? '') === 'current';

                    if ($done) {
                        $tooltip = 'Validé par ' . $email . ' le ' . \App\Core\App::html()->formatDateTimeFr((string) ($token['done_at'] ?? ''));
                        $tooltip .= \App\Core\App::html()->formatRelanceSuffix($relance);
                        if ($relance > 0 && isset($token['relance_at']) && $token['relance_at'] !== '' && $token['relance_at'] !== '0') {
                            $tooltip .= ' (dernier le ' . \App\Core\App::html()->formatDateTimeFr((string) $token['relance_at']) . ')';
                        }
                        $icon = '<span class="wf-check" aria-hidden="true" title="' . \App\Core\App::html()->escape($tooltip) . '">✓</span>';
                    } elseif ($is_current) {
                        $tooltip = 'Email envoyé le ' . \App\Core\App::html()->formatDateTimeFr((string) ($token['sent_at'] ?? ''));
                        if (isset($token['expires_at']) && $token['expires_at'] !== '' && $token['expires_at'] !== '0') {
                            $tooltip .= ' — expire le ' . \App\Core\App::html()->formatDateTimeFr((string) $token['expires_at']);
                        }
                        $tooltip .= \App\Core\App::html()->formatRelanceSuffix($relance);
                        if ($relance > 0 && isset($token['relance_at']) && $token['relance_at'] !== '' && $token['relance_at'] !== '0') {
                            $tooltip .= ' (dernier le ' . \App\Core\App::html()->formatDateTimeFr((string) $token['relance_at']) . ')';
                        }
                        $icon = '<span class="wf-pending" aria-hidden="true" title="' . \App\Core\App::html()->escape($tooltip) . '">⏳</span>';
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

    /**
     * Actions admin (rappeler / régénérer).
     *
     * @param array<int, array{id: string, email: string, done_at: string}> $all_tokens
     */
    public function renderWorkflowActions(array $all_tokens, bool $is_admin, string $status): string
    {
        if (!$is_admin || $status !== SubmissionStatus::EnCours->value) {
            return '';
        }

        $forms_html = '';
        foreach ($all_tokens as $all_token) {
            if (!empty($all_token['done_at'])) {
                continue;
            }
            $tok_id  = \App\Core\App::html()->escape((string) ($all_token['id'] ?? ''));
            $email   = \App\Core\App::html()->displayUser((string) ($all_token['email'] ?? ''));
            $csrf    = \App\Core\App::security()->csrfField();

            $forms_html .= <<<HTML
                          <form method="POST" class="u-dis-2">
                            {$csrf}
                            <input type="hidden" name="action" value="remind_one">
                            <input type="hidden" name="token_id" value="{$tok_id}">
                            <button type="submit" class="btn btn-secondary btn-xs-4"><span aria-hidden="true">📧</span> Rappeler {$email}</button>
                          </form>
                          <form method="POST" class="u-dis-2">
                            {$csrf}
                            <input type="hidden" name="action" value="regenerate_token">
                            <input type="hidden" name="token_id" value="{$tok_id}">
                            <button type="submit" class="btn btn-secondary btn-xs-4"><span aria-hidden="true">🔄</span> Régénérer {$email}</button>
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

    /**
     * Formulaire de délégation.
     *
     * @param array<int, array{id: string, email: string, ordre: int, done_at: string}> $all_tokens
     */
    public function renderDelegationForm(array $all_tokens, string $user, bool $is_admin, string $status): string
    {
        if ($status !== SubmissionStatus::EnCours->value) {
            return '';
        }

        $my_pending = array_filter($all_tokens, fn(array $tok) => empty($tok['done_at']) && ($is_admin || $tok['email'] === $user));

        if ($my_pending === []) {
            return '';
        }

        $options_html = '';
        foreach ($my_pending as $mpt) {
            $id    = \App\Core\App::html()->escape((string) ($mpt['id'] ?? ''));
            $ordre = (int) ($mpt['ordre'] ?? 0);
            $email = \App\Core\App::html()->displayUser((string) ($mpt['email'] ?? ''));
            $options_html .= "<option value=\"{$id}\">Étape {$ordre} — {$email}</option>";
        }

        $csrf = \App\Core\App::security()->csrfField();

        return <<<HTML
                <div class="actions-bar mt-0">
                  <strong class="u-col-fon-16"><span aria-hidden="true">🔄</span> Déléguer ma validation :</strong>
                  <form method="POST" class="u-ali-dis-fle-gap">
                    {$csrf}
                    <input type="hidden" name="action" value="delegate_token">
                    <select name="token_id" class="input-filter-4">
                      {$options_html}
                    </select>
                    <input type="email" name="delegate_to" placeholder="email@dreets.gouv.fr" required class="input-filter-3">
                    <input type="text" name="delegate_reason" placeholder="Motif (optionnel)" class="input-filter-2">
                    <button type="submit" class="btn btn-secondary u-bac-col-fon-pad"><span aria-hidden="true">🔄</span> Déléguer</button>
                  </form>
                </div>
            HTML;
    }

    /**
     * Données du formulaire.
     *
     * @param array<string, mixed> $data
     * @param array<string, array{card_group: string, label: string}> $field_info
     */
    public function renderFormData(array $data, array $field_info): string
    {
        $items_html = '';
        $current_group = '';
        foreach ($data as $k => $v) {
            if ($k === 'validations') {
                continue;
            }
            if ($v === '' || $v === null || $v === '0' && $v !== '0') {
                continue;
            }

            $group = isset($field_info[$k]) ? $field_info[$k]['card_group'] : '';
            $label = isset($field_info[$k])
                ? $field_info[$k]['label']
                : ucfirst(is_string($k) ? str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $k) ?? $k) : '');
            $display_val = $v === '1' ? '✓ Oui' : ($v === '0' ? 'Non' : \App\Core\App::html()->escape((string) $v));

            if ($group !== $current_group && !$group === '' || $group === null || $group === '0') {
                $current_group = $group;
                $group_h = \App\Core\App::html()->escape($group);
                $items_html .= <<<HTML
                            <div class="data-group-title">{$group_h}</div>
                    HTML;
            }

            $label_h = \App\Core\App::html()->escape((string) $label);
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

    /**
     * Données des validateurs.
     *
     * @param array<int, array{field_name: string, field_label: string, value: string, filled_by_email?: string, step_label?: string, filled_at?: string}> $validator_data_rows
     * @param array<string, array{label: string}> $field_info
     */
    public function renderValidatorData(array $validator_data_rows, array $field_info, bool $can_edit = false, string $sub_id = ''): string
    {
        if ($validator_data_rows === []) {
            return '';
        }

        $items_html = '';
        foreach ($validator_data_rows as $validator_data_row) {
            $field_name = (string) ($validator_data_row['field_name'] ?? '');
            $label = isset($field_info[$field_name])
                ? t_jargon($field_info[$field_name]['label'])
                : t_jargon((string) ($validator_data_row['field_label'] ?? $field_name));
            $label_h = \App\Core\App::html()->escape($label);
            $value_raw = (string) ($validator_data_row['value'] ?? '');
            $display_val = \App\Core\App::html()->escape($value_raw);

            $by_email  = isset($validator_data_row['filled_by_email']) ? (string) $validator_data_row['filled_by_email'] : '';
            $step_lab  = isset($validator_data_row['step_label']) ? (string) $validator_data_row['step_label'] : '';
            $filled_at = isset($validator_data_row['filled_at']) ? (string) $validator_data_row['filled_at'] : '';

            $audit_parts   = ['Rempli'];
            if ($by_email !== '') {
                $audit_parts[] = ' par ' . \App\Core\App::html()->displayUser($by_email);
            }
            if ($step_lab !== '') {
                $audit_parts[] = ' — étape : ' . \App\Core\App::html()->escape(t_jargon($step_lab));
            }
            if ($filled_at !== '') {
                $ts = strtotime($filled_at);
                if ($ts !== false) {
                    $audit_parts[] = ' le ' . \App\Core\App::html()->escape(date('d/m/Y à H:i', $ts));
                }
            }
            $audit_line = implode('', $audit_parts);

            if ($can_edit) {
                $csrf         = \App\Core\App::security()->csrfField();
                $sub_id_h     = \App\Core\App::html()->escape($sub_id);
                $fname_h      = \App\Core\App::html()->escape($field_name);
                $value_input  = \App\Core\App::html()->escape($value_raw);
                $value_block = <<<HTML
                              <form method="POST" class="flex-gap5-mt-2">
                                {$csrf}
                                <input type="hidden" name="action" value="update_validator_field">
                                <input type="hidden" name="sub_id" value="{$sub_id_h}">
                                <input type="hidden" name="field_name" value="{$fname_h}">
                                <input type="text" name="value" value="{$value_input}" aria-label="Valeur du champ" class="flex">
                                <button type="submit" class="btn btn-secondary btn-xs-3"><span aria-hidden="true">✏️</span> Modifier</button>
                              </form>
                    HTML;
            } else {
                $value_block = <<<HTML
                              <div class="data-value">{$display_val}</div>
                    HTML;
            }

            $items_html .= <<<HTML
                        <div class="data-item styled-box">
                          <div class="data-label">{$label_h}</div>
                          {$value_block}
                          <div class="hint-muted">{$audit_line}</div>
                        </div>
                HTML;
        }

        $edit_hint = $can_edit
            ? '<p class="hint mb-1-2">Informations saisies par les validateurs au cours du circuit. <strong>Vous pouvez modifier ces champs.</strong></p>'
            : '<p class="hint mb-1-2">Informations saisies par les validateurs au cours du circuit.</p>';

        return <<<HTML
              <!-- Données des validateurs (filled_by='validator') — Option A -->
              <div class="card u-bor" id="validator-data">
                <h2><span aria-hidden="true">🛡️</span> Données des validateurs</h2>
                {$edit_hint}
                <div class="data-grid">
                  {$items_html}
                </div>
              </div>
            HTML;
    }

    /**
     * Historique des validations.
     *
     * @param array<string, mixed> $data
     */
    public function renderValidationHistory(array $data): string
    {
        if (!isset($data['validations']) || !is_array($data['validations']) || empty($data['validations'])) {
            return '';
        }

        $items_html = '';
        foreach ($data['validations'] as $v) {
            $action = (string) ($v['action'] ?? '');
            $is_valid = $action === ValidationAction::Valider->value;
            $is_annule = $action === ValidationAction::Annule->value;
            // CS-04 : 3 états distincts — Validé (vert ✅), Refusé (rouge ❌),
            // Annulé (orange ⚠️). Avant, l'annulation était enregistrée comme
            // 'refuser' → affichée en rouge avec icône ❌ et label 'Refusé', ce qui
            // était trompeur pour l'agent (≠ un refus validateur).
            $icon = $is_valid ? '✅' : ($is_annule ? '⚠️' : '❌');
            $step_label = \App\Core\App::html()->escape((string) ($v['step_label'] ?? ''));
            $email_display = \App\Core\App::html()->displayUser((string) ($v['email'] ?? ''));
            $color_cls = $is_valid ? 'text-valide' : ($is_annule ? 'text-annule' : 'text-refuse');
            $action_label = $is_valid ? 'Validé' : ($is_annule ? 'Annulé' : 'Refusé');
            $date = \App\Core\App::html()->escape((string) ($v['date'] ?? ''));

            $done_by_html = '';
            $done_by = (string) ($v['done_by'] ?? '');
            if ($done_by !== '' && strcasecmp($done_by, (string) ($v['email'] ?? '')) !== 0) {
                $done_by_display = \App\Core\App::html()->displayUser($done_by);
                $done_by_html = <<<HTML
                              <div class="val-done-by"><span aria-hidden="true">👤</span> Action effectuée par : {$done_by_display}</div>
                    HTML;
            }

            $comment_html = '';
            if (!empty($v['commentaire'])) {
                $comment = \App\Core\App::html()->escape((string) $v['commentaire']);
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
                          <span class="{$color_cls}">
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

    /**
     * Historique des relances / notifications envoyées.
     *
     * @param array<int, array{id: string, email: string, done_at: string, relance_count: int, sent_at: string, relance_at: string, expires_at: string}> $all_tokens
     * @param array<int, array{detail: string, created_at: string, actor: string}> $submission_reminds
     * @param array<int, array<string, mixed>> $pending_with_relance
     */
    public function renderRemindHistory(array $all_tokens, array $submission_reminds, int $total_relances, array $pending_with_relance, bool $is_admin, string $status): string
    {
        $pending_html = '';
        if ($pending_with_relance !== [] || ($status === SubmissionStatus::EnCours->value && $all_tokens !== [])) {
            $pending_tokens = array_filter($all_tokens, fn(array $t) => empty($t['done_at']));

            if ($pending_tokens !== []) {
                $rows = '';
                foreach ($pending_tokens as $pending_token) {
                    $email_display = \App\Core\App::html()->displayUser((string) ($pending_token['email'] ?? ''));
                    $relance = (int) ($pending_token['relance_count'] ?? 0);

                    $sent_html = '';
                    if (!empty($pending_token['sent_at'])) {
                        $sent_date = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) $pending_token['sent_at'])));
                        $sent_html = "<span style=\"font-size:.8rem;color:#595959;\">Notifié le : {$sent_date}</span>";
                    }

                    $last_remind = '';
                    if (!empty($pending_token['relance_at'])) {
                        $last_remind_date = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) $pending_token['relance_at'])));
                        $last_remind = "<span style=\"font-size:.8rem;color:#b45309;\">Dernière relance : {$last_remind_date}</span>";
                    }

                    $expires_html = '';
                    if (!empty($pending_token['expires_at'])) {
                        $expires_date = \App\Core\App::html()->escape(date('d/m/Y', (int) strtotime((string) $pending_token['expires_at'])));
                        $expires_html = "<span style=\"font-size:.8rem;color:#595959;\">Expire le : {$expires_date}</span>";
                    }

                    $relance_badge = '';
                    if ($relance > 0) {
                        $sfx = $relance > 1 ? 's' : '';
                        $relance_badge = "<span class=\"badge badge-warn\">{$relance} relance{$sfx}</span>";
                    }

                    $rows .= <<<HTML
                                  <div class="flex-gap5-2">
                                    <span aria-hidden="true" class="u-fon-5">⏳</span>
                                    <strong class="u-fon-2">{$email_display}</strong>
                                    {$relance_badge}
                                    {$sent_html}
                                    {$last_remind}
                                    {$expires_html}
                                  </div>
                        HTML;
                }
                $pending_html = <<<HTML
                          <div class="mb-1">
                            {$rows}
                          </div>
                    HTML;
            }
        }

        $detail_html = '';
        if ($submission_reminds !== []) {
            $rows = '';
            foreach ($submission_reminds as $submission_remind) {
                $detail = \App\Core\App::html()->escape((string) ($submission_remind['detail'] ?? ''));
                $date   = \App\Core\App::html()->escape(date('d/m/Y à H:i', (int) strtotime((string) ($submission_remind['created_at'] ?? 'now'))));
                $actor  = \App\Core\App::html()->displayUser((string) ($submission_remind['actor'] ?? ''));
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
                      <h3 class="caption">Détail des notifications envoyées</h3>
                      {$rows}
                HTML;
        }

        $action_html = '';
        if ($is_admin && $status === SubmissionStatus::EnCours->value) {
            $csrf = \App\Core\App::security()->csrfField();
            $action_html = <<<HTML
                    <div class="actions-bar">
                      <form method="POST">
                        {$csrf}
                        <input type="hidden" name="action" value="remind_all">
                        <button type="submit" class="btn btn-secondary u-fon-2"><span aria-hidden="true">📧</span> Rappeler tous les validateurs en attente</button>
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

    /**
     * Pièces jointes.
     *
     * @param array<int, array{id: string, mime_type: string, original_name: string, file_size: int, uploaded_at: string}> $attachments
     */
    public function renderAttachments(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $count = count($attachments);
        $rows = '';
        foreach ($attachments as $attachment) {
            $icon         = \App\Core\App::html()->getFileIcon((string) ($attachment['mime_type'] ?? ''));
            $name         = \App\Core\App::html()->escape((string) ($attachment['original_name'] ?? ''));
            $mime         = \App\Core\App::html()->escape((string) ($attachment['mime_type'] ?? ''));
            $size         = \App\Core\App::html()->formatFileSize((int) ($attachment['file_size'] ?? 0));
            $date         = \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime((string) ($attachment['uploaded_at'] ?? 'now'))));
            $dl_url       = \App\Core\App::html()->escape('index.php?p=download&id=' . urlencode((string) ($attachment['id'] ?? '')));

            $rows .= <<<HTML
                        <tr>
                          <td class="u-bor-pad">
                            {$icon}
                            <strong>{$name}</strong>
                          </td>
                          <td class="btn-sm-5">{$mime}</td>
                          <td class="btn-sm-7">{$size}</td>
                          <td class="btn-sm-7">{$date}</td>
                          <td class="u-bor-pad-tex-2">
                            <a href="{$dl_url}" class="btn btn-secondary btn-xs"><span aria-hidden="true">📥</span> Télécharger</a>
                          </td>
                        </tr>
                HTML;
        }

        return <<<HTML
              <!-- Pièces jointes -->
              <div class="card">
                <h2><span aria-hidden="true">📎</span> Pièces jointes ({$count})</h2>
                <table class="progress-fill">
                  <thead>
                    <tr>
                      <th class="u-bor-pad-tex">Fichier</th>
                      <th class="u-bor-pad-tex">Type</th>
                      <th class="u-bor-pad-tex">Taille</th>
                      <th class="u-bor-pad-tex">Date</th>
                      <th class="u-bor-pad-tex-3"></th>
                    </tr>
                  </thead>
                  <tbody>
                  {$rows}
                  </tbody>
                </table>
              </div>
            HTML;
    }
}
