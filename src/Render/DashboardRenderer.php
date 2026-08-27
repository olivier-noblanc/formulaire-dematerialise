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
        $css ??= (string) file_get_contents(__DIR__ . '/../../lib/dashboard_page.css');
        return $css;
    }

    /**
     * Encart « État du système » (S5-B / Action 3).
     *
     * @param array{smtp_host: string, smtp_port: int, smtp_ok: bool, smtp_label: string, last_backup: string, en_cours: int} $sys
     */
    public static function systemOverview(array $sys): string
    {
        $smtp_host   = App::html()->escape((string) ($sys['smtp_host'] ?? ''));
        $smtp_port   = (int) ($sys['smtp_port'] ?? 0);
        $smtp_ok     = (bool) ($sys['smtp_ok'] ?? false);
        $smtp_label  = App::html()->escape((string) ($sys['smtp_label'] ?? 'Non configuré'));
        $last_backup = App::html()->escape((string) ($sys['last_backup'] ?? '—'));
        $en_cours    = (int) ($sys[SubmissionStatus::EnCours->value] ?? 0);
        $smtp_dot    = $smtp_ok ? '🟢' : '🔴';

        ob_start();
        require __DIR__ . '/templates/system_overview.php';
        return (string) ob_get_clean();
    }

    /**
     * Blocs de messages d'information issus des actions POST.
     */
    public static function messages(string $regen_msg, string $remind_msg, string $cancel_msg): string
    {
        $out = '';
        if ($regen_msg !== '') {
            $m = App::html()->escape($regen_msg);
            $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
        }
        if ($remind_msg !== '') {
            $m = App::html()->escape($remind_msg);
            $out .= "<div class=\"msg-info\" role=\"status\" aria-live=\"polite\">{$m}</div>";
        }
        if ($cancel_msg !== '') {
            $m = App::html()->escape($cancel_msg);
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
        $filtre_h = App::html()->escape($filtre);
        $form_h   = App::html()->escape($form_f);
        $search_h = App::html()->escape($search);

        $options = '';
        foreach ($forms as $form) {
            $slug  = App::html()->escape((string) ($form['slug'] ?? ''));
            $label = App::html()->escape((string) ($form['label'] ?? ''));
            $sel   = ($form_f === ($form['slug'] ?? '')) ? ' selected' : '';
            $options .= "<option value=\"{$slug}\"{$sel}>{$label}</option>";
        }

        $search_bar = new FormRenderer()->searchBar('index.php?p=dashboard', $search, 'Rechercher (agent, formulaire, données)...', ['statut' => $filtre, 'form' => $form_f]);

        ob_start();
        require __DIR__ . '/templates/toolbar.php';
        return (string) ob_get_clean();
    }

    /**
     * Légende des badges de statut (ITER1-B / Action A).
     */
    public static function statusLegend(): string
    {
        ob_start();
        require __DIR__ . '/templates/status_legend.php';
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

        $tableRenderer = new DashboardTableRenderer();

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
        $content .= $tableRenderer->table($rows, $tokens_by_submission, $validator_status_by_submission);

        return $content . App::html()->renderPagination($page, $total_pages, 'index.php?p=dashboard&' . http_build_query([
            'statut' => $filtre,
            'form'   => $form_f,
            'search' => $search,
        ]));
    }
}
