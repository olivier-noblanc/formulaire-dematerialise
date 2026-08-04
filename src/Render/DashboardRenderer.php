<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\SubmissionStatus;

/**
 * Rendu de la page tableau de bord (dashboard.php).
 *
 * Chaque fonction render_dashboard_*() historique devient une méthode statique.
 * Les wrappers globaux dans lib/render_dashboard.php assurent la rétrocompatibilité.
 */
final class DashboardRenderer
{
    /**
     * CSS propre au tableau de bord (chargé depuis lib/dashboard_page.css).
     */
    public static function pageCss(): string
    {
        static $css = null;
        if ($css === null) {
            $css = (string) file_get_contents(__DIR__ . '/../../lib/dashboard_page.css');
        }
        return $css;
    }

    /**
     * Encart « État du système » (S5-B / Action 3).
     *
     * @param array{smtp_host: string, smtp_port: int, smtp_ok: bool, smtp_label: string, last_backup: string, en_cours: int} $sys
     */
    public static function systemOverview(array $sys): string
    {
        $smtp_host   = \App\Core\App::html()->escape((string) ($sys['smtp_host'] ?? ''));
        $smtp_port   = (int) ($sys['smtp_port'] ?? 0);
        $smtp_ok     = (bool) ($sys['smtp_ok'] ?? false);
        $smtp_label  = \App\Core\App::html()->escape((string) ($sys['smtp_label'] ?? 'Non configuré'));
        $last_backup = \App\Core\App::html()->escape((string) ($sys['last_backup'] ?? '—'));
        $en_cours    = (int) ($sys[SubmissionStatus::EnCours->value] ?? 0);
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

    /**
     * Blocs de messages d'information issus des actions POST.
     */
    public static function messages(string $regen_msg, string $remind_msg, string $cancel_msg): string
    {
        $out = '';
        if ($regen_msg !== '') {
            $m = \App\Core\App::html()->escape($regen_msg);
            $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
        }
        if ($remind_msg !== '') {
            $m = \App\Core\App::html()->escape($remind_msg);
            $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
        }
        if ($cancel_msg !== '') {
            $m = \App\Core\App::html()->escape($cancel_msg);
            $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
        }
        return $out;
    }

    /**
     * Bandeau de 4 statistiques globales.
     */
    public static function stats(int $total, int $complet, int $valide, int $refuse): string
    {
        $en_cours = $total - $complet;
        return <<<HTML
              <div class="stats">
                <div class="stat"><strong>{$total}</strong>Total</div>
                <div class="stat"><strong class="text-warning">{$en_cours}</strong>En cours</div>
                <div class="stat"><strong class="text-success">{$valide}</strong>Validés</div>
                <div class="stat"><strong class="text-danger">{$refuse}</strong>Refusés</div>
              </div>

            HTML;
    }

    /**
     * Barre d'outils du tableau de bord (U-13 — 3 niveaux hiérarchiques).
     *
     * @param array<int, array{slug: string, label: string}> $forms
     */
    public static function toolbar(string $filtre, string $form_f, string $search, array $forms): string
    {
        $filtre_h = \App\Core\App::html()->escape($filtre);
        $form_h   = \App\Core\App::html()->escape($form_f);
        $search_h = \App\Core\App::html()->escape($search);

        $options = '';
        foreach ($forms as $form) {
            $slug  = \App\Core\App::html()->escape((string) ($form['slug'] ?? ''));
            $label = \App\Core\App::html()->escape((string) ($form['label'] ?? ''));
            $sel   = ($form_f === ($form['slug'] ?? '')) ? ' selected' : '';
            $options .= "<option value=\"{$slug}\"{$sel}>{$label}</option>";
        }

        $search_bar = new FormRenderer()->searchBar('index.php?p=dashboard', $search, 'Rechercher (agent, formulaire, données)...', ['statut' => $filtre, 'form' => $form_f]);

        return <<<HTML
              <div class="toolbar">
                <div class="toolbar-filters">
                  <form method="GET" class="u-ali-dis-gap">
                    <input type="hidden" name="statut" value="{$filtre_h}">
                    <label for="filter-form" class="sr-only">Filtrer par formulaire</label>
                    <select name="form" id="filter-form" class="form-filter">
                      <option value="">Tous les formulaires</option>
                      {$options}
                    </select>
                    <button type="submit" class="btn-admin btn-sm-9">OK</button>
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
                  <!-- Niveau 3 — Tertiary : actions avancées VISIBLES (VÉTO 2 M. Robert). -->
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

    /**
     * Légende des badges de statut (ITER1-B / Action A).
     */
    public static function statusLegend(): string
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

    /**
     * Tableau des soumissions.
     *
     * @param array<int, array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, form_label: string, form_slug: string, deadline_field: string}> $rows
     * @param array<string, list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}>> $tokens_by_submission
     * @param array<string, array{total: int, filled: int, complet: bool}> $validator_status_by_submission
     */
    public static function table(array $rows, array $tokens_by_submission, array $validator_status_by_submission = []): string
    {
        $html = "  <table>\n    <thead>\n      <tr>\n";
        $html .= "        <th>Formulaire</th>\n";
        $html .= "        <th>Agent</th>\n";
        $html .= "        <th>Date cible</th>\n";
        $html .= "        <th>Étapes</th>\n";
        $html .= "        <th>Soumis le</th>\n";
        $html .= "        <th>État</th>\n";
        $html .= "        <th></th>\n";
        $html .= "      </tr>\n    </thead>\n    <tbody>\n";

        if ($rows === []) {
            $html .= "      <tr><td colspan=\"7\" class=\"u-c-muted-ta-center-p-2\">Aucune soumission.</td></tr>\n";
        } else {
            foreach ($rows as $i => $row) {
                $tokens = $tokens_by_submission[$row['id']] ?? [];
                $vstatus = $validator_status_by_submission[$row['id']] ?? null;
                $html .= self::submissionRow($i, $row, $tokens, $vstatus);
            }
        }
        return $html . "    </tbody>\n  </table>\n\n";
    }

    /**
     * Une ligne du tableau des soumissions + son bloc <details>.
     *
     * @param array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, form_label: string, form_slug: string, deadline_field: string} $row
     * @param list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}> $tokens
     * @param array{total: int, filled: int, complet: bool}|null $vstatus
     */
    public static function submissionRow(int $i, array $row, array $tokens, ?array $vstatus = null): string
    {
        $d      = json_decode((string) ($row['data'] ?? ''), true);
        $nom    = \App\Core\App::html()->escape(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
        $status = (string) ($row['status'] ?? SubmissionStatus::EnCours->value);
        $deadline_field = (string) ($row['deadline_field'] ?? '');
        $deadline_val   = $deadline_field !== '' && $deadline_field !== '0'
            ? ((string) ($d[$deadline_field] ?? ''))
            : ((string) ($d['date_prise_poste'] ?? $d['date_depart'] ?? ''));
        $dl = calculate_deadline_urgency($deadline_val ?? '', $status);
        $deadline_urgency = (string) ($dl['style'] ?? '');

        $form_label = \App\Core\App::html()->escape(t_jargon((string) ($row['form_label'] ?? '')));
        $submitted_ts = strtotime((string) ($row['submitted_at'] ?? ''));
        $submitted    = $submitted_ts !== false ? \App\Core\App::html()->escape(date('d/m/Y', $submitted_ts)) : '—';
        $view_url     = 'index.php?p=submission_view&id=' . urlencode((string) ($row['id'] ?? ''));

        $tokens_html = '';
        $pending_ordres = array_column(array_filter($tokens, fn(array $x) => !$x['done_at']), 'ordre');
        $min_pending = $pending_ordres !== [] ? min($pending_ordres) : 0;
        foreach ($tokens as $token) {
            if (!empty($token['done_at'])) {
                $cls = 'token-ok';
            } elseif ((int) ($token['ordre'] ?? 0) === (int) $min_pending) {
                $cls = 'token-wait';
            } else {
                $cls = 'token-pend';
            }
            $ordre = (int) ($token['ordre'] ?? 0);
            $label = \App\Core\App::html()->escape((string) ($token['label'] ?? ''));
            $check = empty($token['done_at']) ? '' : ' ✓';
            $tokens_html .= "<span class=\"token-badge {$cls}\">"
                . "<span class=\"ordre-label\">{$ordre}</span>{$label}{$check}"
                . '</span>';
        }

        if ($status === SubmissionStatus::Refuse->value) {
            $etat = '<span class="u-col-fon-10"><span aria-hidden="true">❌</span> Refusé</span>';
        } elseif ($status === SubmissionStatus::Annule->value) {
            $etat = '<span class="u-col-fon-11"><span aria-hidden="true">🗑</span> Annulé</span>';
        } elseif ($status === SubmissionStatus::Valide->value) {
            $etat = '<span class="u-col-fon-14"><span aria-hidden="true">✓</span> Validé</span>';
        } else {
            $etat = '<span class="text-warning"><span aria-hidden="true">⏳</span> En cours</span>';
        }

        $validator_badge = '';
        if ($vstatus !== null && ($status === SubmissionStatus::EnCours->value || $status === SubmissionStatus::Valide->value)) {
            if ($vstatus['complet']) {
                $total = (int) $vstatus['total'];
                $validator_badge = '<div title="Tous les champs validateur sont remplis (' . $total . ' / ' . $total . ')." class="hint-text">'
                    . '<span aria-hidden="true">✓</span> Complet</div>';
            } else {
                $filled = (int) $vstatus['filled'];
                $total  = (int) $vstatus['total'];
                $pending = $total - $filled;
                $validator_badge = '<div title="Champs validateur non remplis : ' . $pending . ' / ' . $total . '." class="hint-warning-2">'
                    . '<span aria-hidden="true">🔄</span> Reste à traiter (' . $filled . '/' . $total . ')</div>';
            }
        }

        $admin_comment_html = '';
        $admin_comment_raw = (string) ($row['admin_comment'] ?? '');
        if ($admin_comment_raw !== '') {
            $tooltip = $admin_comment_raw;
            if (mb_strlen($tooltip) > 200) {
                $tooltip = mb_substr($tooltip, 0, 200) . '…';
            }
            $tooltip_h = \App\Core\App::html()->escape($tooltip);
            $admin_comment_html = ' <span aria-hidden="true" title="' . $tooltip_h . '" class="u-cur-fon">💬</span>';
        }

        $detail = self::submissionDetail($d, $status, $tokens, $row);

        $detail_summary = \App\Core\App::html()->escape($nom !== '' ? $nom : (string) ($row['submitted_by'] ?? '')) . ' — ' . $form_label;

        return <<<HTML
                  <tr>
                    <td><span class="styled-box-9">{$form_label}</span></td>
                    <td><strong>{$nom}</strong></td>
                    <td class="u-whi {$deadline_urgency}">{$deadline_val}</td>
                    <td>
                      <div class="token-grid">
                        {$tokens_html}
                      </div>
                    </td>
                    <td class="u-whi">{$submitted}</td>
                    <td>{$etat}{$admin_comment_html}{$validator_badge}</td>
                    <td><a href="{$view_url}" class="u-col-fon-tex-2">voir</a></td>
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
     * Contenu du bloc <details> d'une soumission.
     *
     * @param array{validations?: array<int, array{step_label: string, email: string, action: string, commentaire?: string, date: string}>}|null  $d
     * @param list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}> $tokens
     * @param array{id: string}      $row
     */
    public static function submissionDetail($d, string $status, array $tokens, array $row): string
    {
        $data_array = is_array($d) ? $d : [];
        $form_data_html = (string) new FormRenderer()->submissionData($data_array, ['validations', 'csrf_token'], 'inline');
        $cancel_url = 'index.php?p=confirm_action&action=cancel_submission&submission_id='
            . urlencode((string) ($row['id'] ?? '')) . '&from=dashboard.phpfrom=index.php?p=dashboard';

        ob_start();
        require __DIR__ . '/templates/submission_detail.php';
        return (string) ob_get_clean();
    }

    /**
     * Compose l'ensemble du contenu HTML du tableau de bord.
     *
     * @param array{smtp_host: string, smtp_port: int, smtp_ok: bool, smtp_label: string, last_backup: string, en_cours: int} $sys
     * @param array{total: int, complet: int, valide: int, refuse: int} $stats
     * @param array{filtre: string, form: string, search: string, regen_msg: string, remind_msg: string, cancel_msg: string} $filters
     * @param array<int, array{slug: string, label: string}> $forms
     * @param array<int, array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, form_label: string, form_slug: string, deadline_field: string}> $rows
     * @param array<string, list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}>> $tokens_by_submission
     * @param array<string, array{total: int, filled: int, complet: bool}> $validator_status_by_submission
     */
    public static function content(
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
        $filtre   = (string) ($filters['filtre'] ?? 'tous');
        $form_f   = (string) ($filters['form'] ?? '');
        $search   = (string) ($filters['search'] ?? '');
        $regen    = (string) ($filters['regen_msg'] ?? '');
        $remind   = (string) ($filters['remind_msg'] ?? '');
        $cancel   = (string) ($filters['cancel_msg'] ?? '');

        $content  = '';
        $content .= "  <h1>Tableau de bord — Demandes en cours</h1>\n";

        $content .= self::systemOverview($sys);
        $content .= self::messages($regen, $remind, $cancel);
        $content .= self::stats(
            (int) ($stats['total'] ?? 0),
            (int) ($stats['complet'] ?? 0),
            (int) ($stats[SubmissionStatus::Valide->value] ?? 0),
            (int) ($stats[SubmissionStatus::Refuse->value] ?? 0)
        );
        $content .= self::toolbar($filtre, $form_f, $search, $forms);
        $content .= self::statusLegend();
        $content .= self::table($rows, $tokens_by_submission, $validator_status_by_submission);

        return $content . App::html()->renderPagination($page, $total_pages, 'index.php?p=dashboard&' . http_build_query([
            'statut' => $filtre,
            'form'   => $form_f,
            'search' => $search,
        ]));
    }
}
