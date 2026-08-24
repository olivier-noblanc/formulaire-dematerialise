<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;
use App\Enum\SubmissionField;
use App\Enum\SubmissionStatus;
use App\Forms\SubmissionData;

/**
 * Rendu du tableau des soumissions dans le tableau de bord.
 *
 * Extrait de DashboardRenderer (H-01, 2026-08-05).
 * Gère l'affichage des lignes de soumission avec leurs tokens, statuts
 * et détails dépliables.
 */
final class DashboardTableRenderer
{
    /**
     * Tableau des soumissions.
     *
     * @param array<int, array{id: string, form_id: string, data: string, submitted_by: string, submitted_at: string|null, closed_at: string|null, status: string, admin_comment: string, form_label: string, form_slug: string, deadline_field: string}> $rows
     * @param array<string, list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}>> $tokens_by_submission
     * @param array<string, array{total: int, filled: int, complet: bool}> $validator_status_by_submission
     */
    public function table(array $rows, array $tokens_by_submission, array $validator_status_by_submission = []): string
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
                $html .= $this->submissionRow($i, $row, $tokens, $vstatus);
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
    public function submissionRow(int $i, array $row, array $tokens, ?array $vstatus = null): string
    {
        $d      = json_decode((string) ($row['data'] ?? ''), true);
        $nom    = App::html()->escape(SubmissionData::get($d, SubmissionField::PRENOM) . ' ' . SubmissionData::get($d, SubmissionField::NOM));
        $status = (string) ($row['status'] ?? SubmissionStatus::EnCours->value);
        $deadline_field = (string) ($row['deadline_field'] ?? '');
        $deadline_val   = $deadline_field !== '' && $deadline_field !== '0'
            ? ((string) ($d[$deadline_field] ?? ''))
            : ((string) ($d['date_prise_poste'] ?? $d['date_depart'] ?? ''));
        $dl = calculate_deadline_urgency($deadline_val ?? '', $status);
        $deadline_urgency = (string) ($dl['style'] ?? '');

        $form_label = App::html()->escape(t_jargon((string) ($row['form_label'] ?? '')));
        $submitted_ts = strtotime((string) ($row['submitted_at'] ?? ''));
        $submitted    = $submitted_ts !== false ? App::html()->escape(date('d/m/Y', $submitted_ts)) : '—';
        $view_url     = 'index.php?p=submission_view&id=' . urlencode((string) ($row['id'] ?? ''));

        $tokens_html = '';
        $pending_ordres = array_column(array_filter($tokens, fn(array $x): bool => !(bool) ($x['done_at'])), 'ordre');
        $min_pending = $pending_ordres !== [] ? min($pending_ordres) : 0;
        foreach ($tokens as $token) {
            if ((bool) ($token['done_at'])) {
                $cls = 'token-ok';
            } elseif ((int) ($token['ordre'] ?? 0) === (int) $min_pending) {
                $cls = 'token-wait';
            } else {
                $cls = 'token-pend';
            }
            $ordre = (int) ($token['ordre'] ?? 0);
            $label = App::html()->escape((string) ($token['label'] ?? ''));
            $check = (bool) ($token['done_at']) ? ' ✓' : '';
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
            $tooltip_h = App::html()->escape($tooltip);
            $admin_comment_html = ' <span aria-hidden="true" title="' . $tooltip_h . '" class="u-cur-fon">💬</span>';
        }

        $detail = $this->submissionDetail($d, $status, $tokens, $row);

        $detail_summary = App::html()->escape($nom !== '' ? $nom : (string) ($row['submitted_by'] ?? '')) . ' — ' . $form_label;

        ob_start();
        $form_label_h = $form_label;
        $deadline_urgency_h = $deadline_urgency;
        $deadline_val_h = $deadline_val;
        $submitted_h = $submitted;
        $view_url_h = $view_url;
        $etat_h = $etat;
        $admin_comment_html_h = $admin_comment_html;
        $validator_badge_h = $validator_badge;
        $tokens_html_h = $tokens_html;
        $nom_h = $nom;
        $detail_h = $detail;
        $detail_summary_h = $detail_summary;
        require __DIR__ . '/templates/submission_row.php';
        return (string) ob_get_clean();
    }

    /**
     * Contenu du bloc <details> d'une soumission.
     *
     * @param array{validations?: array<int, array{step_label: string, email: string, action: string, commentaire?: string, date: string}>}|null  $d
     * @param list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}> $tokens
     * @param array{id: string}      $row
     */
    public function submissionDetail($d, string $status, array $tokens, array $row): string
    {
        $data_array = is_array($d) ? $d : [];
        $form_data_html = new FormRenderer()->submissionData($data_array, [SubmissionField::VALIDATIONS->value, 'csrf_token'], 'inline');
        $cancel_url = 'index.php?p=confirm_action&action=cancel_submission&submission_id='
            . urlencode((string) ($row['id'] ?? '')) . '&from=' . urlencode('index.php?p=dashboard');

        ob_start();
        require __DIR__ . '/templates/submission_detail.php';
        return (string) ob_get_clean();
    }
}
