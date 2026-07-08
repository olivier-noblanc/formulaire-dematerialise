<?php
declare(strict_types=1);

/**
 * Rendu de la page tableau de bord (dashboard.php).
 *
 * Contient toutes les fonctions de rendu HTML du tableau de bord admin :
 *  - dashboard_page_css()                  : CSS spécifique (chargé depuis lib/dashboard_page.css)
 *  - render_dashboard_system_overview()    : encart « État du système » (S5-B / Action 3)
 *  - render_dashboard_messages()           : messages d'info (regen / remind / cancel)
 *  - render_dashboard_stats()              : bandeau Total / En cours / Validés / Refusés
 *  - render_dashboard_toolbar()            : barre filtres + actions admin (3 niveaux U-13)
 *  - render_dashboard_status_legend()      : légende des états (ITER1-B / Action A)
 *  - render_dashboard_table()              : tableau des soumissions (itération lignes)
 *  - render_dashboard_submission_row()     : une ligne du tableau + bloc <details>
 *  - render_dashboard_content()            : compose l'ensemble du contenu de page
 *
 * Le CSS volumineux (> 300 lignes) est extrait vers lib/dashboard_page.css
 * pour garder ce fichier sous 600 lignes (refactor « all-under-600 »).
 *
 * @package lib
 * @see /dashboard.php
 * @see /lib/dashboard_page.css
 */

// ── CSS SPÉCIFIQUE TABLEAU DE BORD ─────────────────────────────

/**
 * CSS propre au tableau de bord : toolbar, actions admin (3 niveaux),
 * légende des états, encart état du système, badges token, etc.
 *
 * Retourné sous forme de chaîne pour injection dans render_page($page_css).
 * Le contenu CSS est chargé depuis lib/dashboard_page.css (nowdoc statique)
 * pour éviter de dépasser 600 lignes dans ce fichier. Comportement
 * strictement identique à l'ancien heredoc <<<'CSS' inline.
 */
function dashboard_page_css(): string
{
    static $css = null;
    if ($css === null) {
        // Le fichier .css est livré à côté de ce module ; __DIR__ garantit
        // la résolution même si l'include_path change.
        $css = (string)file_get_contents(__DIR__ . '/dashboard_page.css');
    }
    return $css;
}

// ── ÉTAT DU SYSTÈME (S5-B / Action 3) ──────────────────────────

/**
 * Encart « État du système » — vue d'ensemble pour Mme Laurent (DSI).
 * 4 indicateurs (SMTP, DB, dernière sauvegarde, demandes en attente) +
 * 2 liens vers health.php (Détails) et monitoring.php (Surveillance).
 *
 * @param array<string, mixed> $sys {
 *       string $smtp_host       Hôte SMTP configuré
 *       int    $smtp_port       Port SMTP configuré
 *       bool   $smtp_ok         Connexion SMTP fonctionnelle
 *       string $smtp_label      Étiquette SMTP affichée (« OK », « Erreur », « Non configuré »)
 *       string $last_backup     Date de dernière sauvegarde (d/m/Y) ou « — »
 *       int    $en_cours        Nombre de demandes en cours
 * }
 */
function render_dashboard_system_overview(array $sys): string
{
    $smtp_host   = h((string)($sys['smtp_host'] ?? ''));
    $smtp_port   = (int)($sys['smtp_port'] ?? 0);
    $smtp_ok     = (bool)($sys['smtp_ok'] ?? false);
    $smtp_label  = h((string)($sys['smtp_label'] ?? 'Non configuré'));
    $last_backup = h((string)($sys['last_backup'] ?? '—'));
    $en_cours    = (int)($sys['en_cours'] ?? 0);
    $smtp_dot    = $smtp_ok ? '🟢' : '🔴';

    return <<<HTML
  <aside class="system-overview" aria-label="État du système">
    <span class="system-overview-title">État du système</span>
    <span class="system-overview-item" title="Connexion SMTP au serveur {$smtp_host}:{$smtp_port}">
      {$smtp_dot} SMTP : <strong>{$smtp_label}</strong>
    </span>
    <span class="system-overview-item" title="Base de données SQLite accessible en lecture/écriture">
      🟢 DB : <strong>OK</strong>
    </span>
    <span class="system-overview-item" title="Date du dernier téléchargement ou restauration de sauvegarde">
      📅 Dernière sauvegarde : <strong>{$last_backup}</strong>
    </span>
    <span class="system-overview-item" title="Demandes en cours de validation">
      📊 Demandes en attente : <strong>{$en_cours}</strong>
    </span>
    <span class="system-overview-links">
      <a href="index.php?p=health" aria-label="Voir les détails de l'état du système">Détails</a>
      <a href="index.php?p=monitoring" aria-label="Aller à la surveillance du système">Surveillance</a>
    </span>
  </aside>

HTML;
}

// ── MESSAGES D'INFO ───────────────────────────────────────────

/**
 * Blocs de messages d'information issus des actions POST
 * (régénération de token, rappel manuel, annulation de soumission).
 */
function render_dashboard_messages(string $regen_msg, string $remind_msg, string $cancel_msg): string
{
    $out = '';
    if ($regen_msg !== '') {
        $m = h($regen_msg);
        $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
    }
    if ($remind_msg !== '') {
        $m = h($remind_msg);
        $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
    }
    if ($cancel_msg !== '') {
        $m = h($cancel_msg);
        $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
    }
    return $out;
}

// ── BANDEAU DE STATISTIQUES ───────────────────────────────────

/**
 * Bandeau de 4 statistiques globales : Total / En cours / Validés / Refusés.
 */
function render_dashboard_stats(int $total, int $complet, int $valide, int $refuse): string
{
    $en_cours = $total - $complet;
    return <<<HTML
  <div class="stats">
    <div class="stat"><strong>{$total}</strong>Total</div>
    <div class="stat"><strong style="color:#b45309;">{$en_cours}</strong>En cours</div>
    <div class="stat"><strong style="color:#1a6b3c;">{$valide}</strong>Validés</div>
    <div class="stat"><strong style="color:#c0392b;">{$refuse}</strong>Refusés</div>
  </div>

HTML;
}

// ── BARRE D'OUTILS + ACTIONS ADMIN (U-13) ─────────────────────

/**
 * Barre d'outils du tableau de bord : filtres (statut / formulaire / recherche)
 * + bloc d'actions admin sur 3 niveaux hiérarchiques (Primary / Secondary / Tertiary).
 *
 * U-13 — Hiérarchie des actions admin pour guider l'admin novice DREETS :
 *  - Primary : Formulaires, Alertes (gradient Marianne bleu, 90% du temps)
 *  - Secondary : Surveillance, Statistiques (outline discret)
 *  - Tertiary : Export CSV, RGPD (amber / rouge — visibles VÉTO 2 M. Robert)
 */
function render_dashboard_toolbar(string $filtre, string $form_f, string $search, array $forms): string
{
    $filtre_h = h($filtre);
    $form_h   = h($form_f);
    $search_h = h($search);

    // Liste déroulante des formulaires actifs
    $options = '';
    foreach ($forms as $f) {
        $slug  = h((string)($f['slug'] ?? ''));
        $label = h((string)($f['label'] ?? ''));
        $sel   = ($form_f === ($f['slug'] ?? '')) ? ' selected' : '';
        $options .= "<option value=\"{$slug}\"{$sel}>{$label}</option>";
    }

    $search_bar    = render_search_bar('index.php?p=dashboard', $search, 'Rechercher (agent, formulaire, données)...', ['statut' => $filtre, 'form' => $form_f]);

    return <<<HTML
  <div class="toolbar">
    <div class="toolbar-filters">
      <form method="GET" style="display:inline-flex;gap:.5rem;align-items:center;">
        <input type="hidden" name="statut" value="{$filtre_h}">
        <label for="filter-form" class="sr-only">Filtrer par formulaire</label>
        <select name="form" id="filter-form" class="form-filter">
          <option value="">Tous les formulaires</option>
          {$options}
        </select>
        <button type="submit" class="btn-admin" style="padding:.3rem .8rem;font-size:.8rem;">OK</button>
      </form>
      {$search_bar}
    </div>
    <nav class="admin-actions" aria-label="Actions d'administration">
      <!-- Niveau 1 — Primary : actions principales (90% du temps admin).
           Gros boutons Marianne bleu (gradient) — attirent l'œil en priorité. -->
      <div class="admin-actions-row">
        <span class="admin-actions-label">Actions principales</span>
        <div class="admin-actions-btns" role="group" aria-label="Actions principales">
          <a href="index.php?p=admin_forms" class="btn-admin" aria-label="Gérer les formulaires">
            <span aria-hidden="true">⚙</span> Formulaires
          </a>
          <a href="index.php?p=admin_alerts" class="btn-admin" aria-label="Configurer les alertes automatiques">
            <span aria-hidden="true">🔔</span> Alertes
          </a>
        </div>
      </div>
      <!-- Niveau 2 — Secondary : consultation fréquente (mais pas action principale).
           Outline Marianne bleu discret — visuellement secondaire. -->
      <div class="admin-actions-row">
        <span class="admin-actions-label">Consultation</span>
        <div class="admin-actions-btns" role="group" aria-label="Consultation">
          <a href="index.php?p=monitoring" class="btn-admin btn-admin--secondary" aria-label="Surveillance du système en temps réel">
            <span aria-hidden="true">🖥</span> Surveillance
          </a>
          <a href="index.php?p=stats" class="btn-admin btn-admin--secondary" aria-label="Consulter les statistiques d'utilisation">
            <span aria-hidden="true">📊</span> Statistiques
          </a>
        </div>
      </div>
      <!-- Niveau 3 — Tertiary : actions avancées VISIBLES (VÉTO 2 M. Robert).
           Anciennement cachées dans <details>, désormais affichées mais
           hiérarchiquement marquées (amber discret + séparateur pointillé).
           M. Robert (70 ans) interprétait le <details> comme un défaut de l'app :
           il pensait que les actions étaient cachées par erreur. On les rend
           visibles, en conservant la distinction visuelle (tertiary + danger). -->
      <div class="admin-actions-row admin-actions-advanced">
        <span class="admin-actions-label">Actions avancées <span class="admin-actions-label-hint">— à utiliser ponctuellement</span></span>
        <div class="admin-actions-btns" role="group" aria-label="Actions avancées (export et protection des données)">
          <a href="index.php?p=dashboard&export=csv&statut={$filtre_h}&form={$form_h}&search={$search_h}" class="btn-admin btn-admin--tertiary" aria-label="Exporter les soumissions filtrées au format CSV">
            <span aria-hidden="true">📥</span> Export CSV
          </a>
          <a href="index.php?p=rgpd" class="btn-admin btn-admin--danger" aria-label="Gérer la protection des données (RGPD) et la purge">
            <span aria-hidden="true">🔐</span> Protection des données
          </a>
        </div>
      </div>
    </nav>
  </div>

HTML;
}

// ── LÉGENDE DES ÉTATS (ITER1-B / Action A) ────────────────────

/**
 * Légende des badges de statut (En cours / Validé / Refusé) affichée au-dessus
 * du tableau. M. Robert (70 ans) ne savait pas à quoi correspondent les couleurs
 * sans exemple explicite.
 */
function render_dashboard_status_legend(): string
{
    return <<<HTML
  <aside class="status-legend" aria-label="Légende des états">
    <span class="status-legend-title">États :</span>
    <span class="badge badge-warn">🟡 En cours</span>
    <span class="status-legend-text">Demande en cours de validation</span>
    <span class="badge badge-ok">🟢 Validé</span>
    <span class="status-legend-text">Demande validée</span>
    <span class="badge badge-err">🔴 Refusé</span>
    <span class="status-legend-text">Demande refusée (motif indiqué)</span>
  </aside>

HTML;
}

// ── TABLEAU DES SOUMISSIONS ───────────────────────────────────

/**
 * Tableau des soumissions avec colonnes : Formulaire / Agent / Date cible /
 * Étapes (workflow) / Soumis le / État / (action voir).
 *
 * Chaque ligne est suivie d'une ligne <details> dépliable avec le détail
 * de la demande (validations, données, actions admin par token).
 *
 * @param array<int, array<string, mixed>> $rows                   Lignes de soumissions
 * @param array<string, array<int, array<string, mixed>>> $tokens_by_submission Tokens préchargés (A-13), indexés par submission_id
 * @param array<string, array{total: int, filled: int, complet: bool}> $validator_status_by_submission BACKLOG : indicateur reste à traiter
 */
function render_dashboard_table(array $rows, array $tokens_by_submission, array $validator_status_by_submission = []): string
{
    $html = "  <table>\n    <thead>\n      <tr>\n";
    $html .= "        <th>Formulaire</th>\n";
    $html .= "        <th>Agent</th>\n";
    $html .= "        <th>Date cible</th>\n";
    // ITER1-B / Action A : « Workflow » (jargon) → « Étapes » (clair pour M. Robert).
    $html .= "        <th>Étapes</th>\n";
    $html .= "        <th>Soumis le</th>\n";
    // ITER1-B / Action A : « Statut » → « État » (plus courant en français).
    $html .= "        <th>État</th>\n";
    $html .= "        <th></th>\n";
    $html .= "      </tr>\n    </thead>\n    <tbody>\n";

    if (empty($rows)) {
        $html .= "      <tr><td colspan=\"7\" style=\"text-align:center;padding:2rem;color:#595959;\">Aucune soumission.</td></tr>\n";
    } else {
        foreach ($rows as $i => $row) {
            $tokens = $tokens_by_submission[$row['id']] ?? [];
            $vstatus = $validator_status_by_submission[$row['id']] ?? null;
            $html .= render_dashboard_submission_row($i, $row, $tokens, $vstatus);
        }
    }

    $html .= "    </tbody>\n  </table>\n\n";
    return $html;
}

/**
 * Une ligne du tableau des soumissions + son bloc <details> dépliable.
 *
 * @param int               $i      Index de la ligne (non utilisé mais conservé pour signature stable)
 * @param array<string, mixed> $row Ligne de soumission (s.*, f.label as form_label, f.slug, f.deadline_field)
 * @param array<int, array<string, mixed>> $tokens Tokens de cette soumission (déjà triés par ordre)
 * @param array{total: int, filled: int, complet: bool}|null $vstatus BACKLOG : état complétion champs validator
 */
function render_dashboard_submission_row(int $i, array $row, array $tokens, ?array $vstatus = null): string
{
    $d      = json_decode((string)($row['data'] ?? ''), true);
    $nom    = h(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
    $status = (string)($row['status'] ?? 'en_cours');
    $deadline_field = (string)($row['deadline_field'] ?? '');
    $deadline_val   = $deadline_field
        ? ((string)($d[$deadline_field] ?? ''))
        : ((string)($d['date_prise_poste'] ?? $d['date_depart'] ?? ''));
    // Calculer l'urgence si on a une date cible
    $dl = calculate_deadline_urgency($deadline_val ?: '', $status);
    $deadline_urgency = (string)($dl['style'] ?? '');

    $form_label = h(t_jargon((string)($row['form_label'] ?? '')));
    // Date au format français (JJ/MM/AAAA) au lieu de l'ISO brut (YYYY-MM-DD).
    $submitted_ts = strtotime((string)($row['submitted_at'] ?? ''));
    $submitted    = $submitted_ts !== false ? h(date('d/m/Y', $submitted_ts)) : '—';
    $view_url     = 'index.php?p=submission_view&id=' . urlencode((string)($row['id'] ?? ''));

    // ── Cellule « Étapes » : grille de badges token ──
    // A-13: optimisé — tokens préchargés en batch (plus de N+1).
    // Pour chaque token :
    //   - done_at       → token-ok (✓ vert)
    //   - première étape non faite (ordre min) → token-wait (pulse ambre)
    //   - sinon         → token-pend (gris)
    $tokens_html = '';
    $pending_ordres = array_column(array_filter($tokens, fn($x) => !$x['done_at']), 'ordre');
    $min_pending = $pending_ordres !== [] ? min($pending_ordres) : 0;
    foreach ($tokens as $t) {
        if (!empty($t['done_at'])) {
            $cls = 'token-ok';
        } elseif ((int)($t['ordre'] ?? 0) === (int)$min_pending) {
            $cls = 'token-wait';
        } else {
            $cls = 'token-pend';
        }
        $ordre = (int)($t['ordre'] ?? 0);
        $label = h((string)($t['label'] ?? ''));
        $check = !empty($t['done_at']) ? ' ✓' : '';
        $tokens_html .= "<span class=\"token-badge {$cls}\">"
            . "<span class=\"ordre-label\">{$ordre}</span>{$label}{$check}"
            . "</span>";
    }

    // ── Cellule « État » ──
    // BACKLOG : pour les soumissions 'en_cours' ou 'valide', on ajoute
    // l'indicateur « Reste à traiter » / « Complet » basé sur les champs
    // validator non remplis. Le badge s'affiche sous le libellé de statut.
    if ($status === 'refuse') {
        $etat = '<span style="color:#c0392b;font-weight:bold;"><span aria-hidden="true">❌</span> Refusé</span>';
    } elseif ($status === 'annule') {
        $etat = '<span style="color:#6b7280;font-weight:bold;"><span aria-hidden="true">🗑</span> Annulé</span>';
    } elseif ($status === 'valide') {
        $etat = '<span style="color:#1a6b3c;font-weight:bold;"><span aria-hidden="true">✓</span> Validé</span>';
    } else {
        $etat = '<span style="color:#b45309;"><span aria-hidden="true">⏳</span> En cours</span>';
    }

    // BACKLOG : badge « Reste à traiter » / « Complet »
    // Uniquement pour les soumissions 'en_cours' ou 'valide' (refuse = non concerné).
    $validator_badge = '';
    if ($vstatus !== null && ($status === 'en_cours' || $status === 'valide')) {
        if ($vstatus['complet']) {
            $total = (int)$vstatus['total'];
            $validator_badge = '<div style="margin-top:.25rem;font-size:.7rem;color:#1a6b3c;" title="Tous les champs validateur sont remplis (' . $total . ' / ' . $total . ').">'
                . '<span aria-hidden="true">✓</span> Complet</div>';
        } else {
            $filled = (int)$vstatus['filled'];
            $total  = (int)$vstatus['total'];
            $pending = $total - $filled;
            $validator_badge = '<div style="margin-top:.25rem;font-size:.7rem;color:#b45309;font-weight:600;" title="Champs validateur non remplis : ' . $pending . ' / ' . $total . '.">'
                . '<span aria-hidden="true">🔄</span> Reste à traiter (' . $filled . '/' . $total . ')</div>';
        }
    }

    // BACKLOG : icône 💬 si admin_comment non vide (tooltip avec le commentaire).
    // Affiché dans la cellule « État » à côté du statut, en haut à droite.
    $admin_comment_html = '';
    $admin_comment_raw = (string)($row['admin_comment'] ?? '');
    if ($admin_comment_raw !== '') {
        // Tooltip tronqué pour éviter un title trop long (limitation navigateur ~ 600 chars).
        // On garde une preview raisonnable (200 chars) — au-delà, l'utilisateur ira
        // voir le détail dans submission_view.php.
        $tooltip = $admin_comment_raw;
        if (mb_strlen($tooltip) > 200) {
            $tooltip = mb_substr($tooltip, 0, 200) . '…';
        }
        // Échappement pour l'attribut title (les quotes et < > doivent être neutralisés).
        $tooltip_h = h((string)$tooltip);
        $admin_comment_html = ' <span aria-hidden="true" title="' . $tooltip_h . '" style="cursor:help;font-size:.95rem;">💬</span>';
    }

    // ── Bloc <details> dépliable (historique + données + actions) ──
    $detail = render_dashboard_submission_detail($d, $status, $tokens, $row, $nom);

    $detail_summary = h($nom !== '' ? $nom : (string)($row['submitted_by'] ?? '')) . ' — ' . $form_label;

    return <<<HTML
      <tr>
        <td><span style="font-size:.8rem;background:#e8eaf6;color:#003189;padding:.2rem .5rem;border-radius:3px;">{$form_label}</span></td>
        <td><strong>{$nom}</strong></td>
        <td style="white-space:nowrap;{$deadline_urgency}">{$deadline_val}</td>
        <td>
          <div class="token-grid">
            {$tokens_html}
          </div>
        </td>
        <td style="white-space:nowrap;">{$submitted}</td>
        <td>{$etat}{$admin_comment_html}{$validator_badge}</td>
        <td><a href="{$view_url}" style="font-size:.8rem;color:#003189;text-decoration:underline;">voir</a></td>
      </tr>
      <tr>
        <td colspan="7">
          <details>
            <summary>Détails de la demande — {$detail_summary}</summary>
            <div class="detail-content">
{$detail}
            </div>
          </details>
        </td>
      </tr>

HTML;
}

/**
 * Contenu du bloc <details> d'une soumission :
 *  - Historique des validations (si $d['validations'] existe)
 *  - Données de la soumission (render_submission_data en mode inline)
 *  - Actions admin par token (rappeler / régénérer) si statut en_cours + admin
 *  - Lien d'annulation (confirm_action.php)
 *
 * @param array<string, mixed>|null  $d      Données JSON décodées de la soumission
 * @param string                     $status Statut de la soumission (en_cours / valide / refuse)
 * @param array<int, array<string, mixed>> $tokens Tokens de cette soumission
 * @param array<string, mixed>      $row    Ligne brute (pour submitted_by, id)
 * @param string                    $nom    Nom complet déjà échappé de l'agent
 */
function render_dashboard_submission_detail($d, string $status, array $tokens, array $row, string $nom): string
{
    $html = '';

    // ── Historique des validations ──
    if (is_array($d) && isset($d['validations']) && is_array($d['validations'])) {
        $html .= "              <h3 style=\"margin-top:0;margin-bottom:1rem;\">Historique des validations</h3>\n";
        foreach ($d['validations'] as $validation) {
            $step_label = h((string)($validation['step_label'] ?? ''));
            $email      = h((string)($validation['email'] ?? ''));
            $action     = (string)($validation['action'] ?? '');
            $is_valide  = ($action === 'valider');
            $color      = $is_valide ? '#1a6b3c' : '#c0392b';
            $icon       = $is_valide ? '✅' : '❌';
            $label      = $is_valide ? 'Validé' : 'Refusé';
            $comment    = '';
            if (!empty($validation['commentaire'])) {
                $c = h((string)$validation['commentaire']);
                $comment = "<br><em>Commentaire :</em> {$c}";
            }
            // Formatage de la date en français (JJ/MM/AAAA à HH:MM) —
            // la valeur brute stockée en base est au format ISO UTC (Y-m-d H:i:s)
            // qui est illisible pour un utilisateur non technique.
            $val_date_ts = strtotime((string)($validation['date'] ?? ''));
            $date = $val_date_ts !== false ? h(date('d/m/Y à H:i', $val_date_ts)) : '—';
            $html .= "              <div style=\"border-left:3px solid #003189;padding-left:1rem;margin-bottom:1rem;\">\n"
                . "                <strong>{$step_label}</strong> - {$email} -\n"
                . "                <span style=\"color:{$color};\">\n"
                . "                  <span aria-hidden=\"true\">{$icon}</span> {$label}\n"
                . "                </span>\n"
                . "                {$comment}\n"
                . "                <br><small>{$date}</small>\n"
                . "              </div>\n";
        }
        $html .= "              <hr style=\"margin:1rem 0;\">\n";
    }

    // ── Données de la soumission (rendu inline) ──
    $data_array = is_array($d) ? $d : [];
    $html .= "              " . render_submission_data($data_array, ['validations', 'csrf_token'], 'inline') . "\n";

    // ── Actions admin par token + annulation (uniquement si en_cours) ──
    if ($status === 'en_cours') {
        $html .= "              <hr style=\"margin:1rem 0;\">\n";
        $html .= "              <div style=\"display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start;\">\n";
        if (\App\Core\App::auth()->isAdminEffective()) {  // v9.9.0 — persona: false si admin en mode visu
            foreach ($tokens as $t) {
                if (!empty($t['done_at'])) {
                    continue;
                }
                $tid   = h((string)($t['id'] ?? ''));
                $temail = h((string)($t['email'] ?? ''));
                // Rappel manuel (remind_one)
                $html .= "                <form method=\"POST\" style=\"display:inline;\">\n"
                    . \App\Core\App::security()->csrfField() . "\n"
                    . "                  <input type=\"hidden\" name=\"action\" value=\"remind_one\">\n"
                    . "                  <input type=\"hidden\" name=\"token_id\" value=\"{$tid}\">\n"
                    . "                  <button type=\"submit\" class=\"btn btn-secondary\" style=\"font-size:.75rem;padding:.3rem .6rem;\"><span aria-hidden=\"true\">📧</span> Rappeler {$temail}</button>\n"
                    . "                </form>\n";
                // Régénération de token (regenerate_token)
                $html .= "                <form method=\"POST\" style=\"display:inline;\">\n"
                    . \App\Core\App::security()->csrfField() . "\n"
                    . "                  <input type=\"hidden\" name=\"action\" value=\"regenerate_token\">\n"
                    . "                  <input type=\"hidden\" name=\"token_id\" value=\"{$tid}\">\n"
                    . "                  <button type=\"submit\" class=\"btn btn-secondary\" style=\"font-size:.75rem;padding:.3rem .6rem;\"><span aria-hidden=\"true\">🔄</span> Régénérer {$temail}</button>\n"
                    . "                </form>\n";
            }
        }
        $cancel_url = 'index.php?p=confirm_action&action=cancel_submission&submission_id='
            . urlencode((string)($row['id'] ?? '')) . '&from=dashboard.phpfrom=index.php?p=dashboard';
        $html .= "                <a href=\"{$cancel_url}\" class=\"btn btn-danger\" style=\"font-size:.75rem;padding:.3rem .6rem;text-decoration:none;\"><span aria-hidden=\"true\">🗑</span> Annuler</a>\n";
        $html .= "              </div>\n";
    }

    return $html;
}

// ── COMPOSITION DU CONTENU DE PAGE ────────────────────────────

/**
 * Compose l'ensemble du contenu HTML du tableau de bord (entre header et footer).
 *
 * Reproduit exactement l'ordre historique des sections de dashboard.php :
 *   1. Fil d'ariane
 *   2. Titre H1 + phrase d'introduction
 *   3. Encart « État du système » (S5-B / Action 3)
 *   4. Messages d'info (regen / remind / cancel)
 *   5. Bandeau de stats (Total / En cours / Validés / Refusés)
 *   6. Barre d'outils + actions admin (U-13 — 3 niveaux)
 *   7. Légende des états (ITER1-B / Action A)
 *   8. Tableau des soumissions
 *   9. Pagination
 *
 * @param array<string, mixed> $sys                   Voir render_dashboard_system_overview()
 * @param array<string, mixed> $stats                 ['total', 'complet', 'valide', 'refuse']
 * @param array<string, mixed> $filters               ['filtre', 'form', 'search']
 * @param array<int, array<string, mixed>> $forms     Formulaires actifs (pour la liste déroulante)
 * @param array<int, array<string, mixed>> $rows      Lignes de soumissions paginées
 * @param array<string, array<int, array<string, mixed>>> $tokens_by_submission Tokens préchargés (A-13)
 * @param array<string, array{total: int, filled: int, complet: bool}> $validator_status_by_submission BACKLOG : indicateur reste à traiter
 * @param int                   $page                  Page courante (1-based)
 * @param int                   $total_pages           Nombre total de pages
 */
function render_dashboard_content(
    array $sys,
    array $stats,
    array $filters,
    array $forms,
    array $rows,
    array $tokens_by_submission,
    array $validator_status_by_submission,
    int $page,
    int $total_pages
): string {
    $filtre   = (string)($filters['filtre'] ?? 'tous');
    $form_f   = (string)($filters['form'] ?? '');
    $search   = (string)($filters['search'] ?? '');
    $regen    = (string)($filters['regen_msg'] ?? '');
    $remind   = (string)($filters['remind_msg'] ?? '');
    $cancel   = (string)($filters['cancel_msg'] ?? '');

    // ITER1-B / Action A : titre explicite (pas de jargon « Workflows ») + phrase d'introduction.
    // v10.0.6 — page-intro supprimé (redondant avec le titre h1)
    $content  = '';  // initialisation explicite (v9.5.0 — fix warning PHP "Undefined variable $content")
    $content .= "  <h1>Tableau de bord — Demandes en cours</h1>\n";

    $content .= render_dashboard_system_overview($sys);
    $content .= render_dashboard_messages($regen, $remind, $cancel);
    $content .= render_dashboard_stats(
        (int)($stats['total'] ?? 0),
        (int)($stats['complet'] ?? 0),
        (int)($stats['valide'] ?? 0),
        (int)($stats['refuse'] ?? 0)
    );
    $content .= render_dashboard_toolbar($filtre, $form_f, $search, $forms);
    $content .= render_dashboard_status_legend();
    $content .= render_dashboard_table($rows, $tokens_by_submission, $validator_status_by_submission);

    $content .= \App\Core\App::html()->renderPagination($page, $total_pages, 'index.php?p=dashboard&' . http_build_query([
        'statut' => $filtre,
        'form'   => $form_f,
        'search' => $search,
    ]));

    return $content;
}
