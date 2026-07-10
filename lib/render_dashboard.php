<?php
declare(strict_types=1);

/**
 * Rendu du tableau de bord admin (dashboard.php) — Wrapper backward-compatible.
 *
 * Les fonctions globales déléguent à App\Render\DashboardRenderer (OOP).
 * Ce fichier assure la rétrocompatibilité avec tous les appels existants.
 *
 * @package lib
 * @see /dashboard.php
 * @see /src/Render/DashboardRenderer.php
 */

use App\Render\DashboardRenderer;

function dashboard_page_css(): string
{
    return DashboardRenderer::pageCss();
}

function render_dashboard_system_overview(array $sys): string
{
    return DashboardRenderer::systemOverview($sys);
}

function render_dashboard_messages(string $regen_msg, string $remind_msg, string $cancel_msg): string
{
    return DashboardRenderer::messages($regen_msg, $remind_msg, $cancel_msg);
}

function render_dashboard_stats(int $total, int $complet, int $valide, int $refuse): string
{
    return DashboardRenderer::stats($total, $complet, $valide, $refuse);
}

function render_dashboard_toolbar(string $filtre, string $form_f, string $search, array $forms): string
{
    return DashboardRenderer::toolbar($filtre, $form_f, $search, $forms);
}

function render_dashboard_status_legend(): string
{
    return DashboardRenderer::statusLegend();
}

function render_dashboard_table(array $rows, array $tokens_by_submission, array $validator_status_by_submission = []): string
{
    return DashboardRenderer::table($rows, $tokens_by_submission, $validator_status_by_submission);
}

function render_dashboard_submission_row(int $i, array $row, array $tokens, ?array $vstatus = null): string
{
    return DashboardRenderer::submissionRow($i, $row, $tokens, $vstatus);
}

function render_dashboard_submission_detail($d, string $status, array $tokens, array $row, string $nom): string
{
    return DashboardRenderer::submissionDetail($d, $status, $tokens, $row, $nom);
}

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
    return DashboardRenderer::content($sys, $stats, $filters, $forms, $rows, $tokens_by_submission, $validator_status_by_submission, $page, $total_pages);
}
