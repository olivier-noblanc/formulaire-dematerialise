<?php
declare(strict_types=1);

/**
 * Rendu de la page d'accueil (index.php) — Wrapper backward-compatible.
 *
 * Les fonctions globales déléguent à App\Render\IndexRenderer (OOP).
 * Ce fichier assure la rétrocompatibilité avec tous les appels existants.
 *
 * @package lib
 * @see /index.php
 * @see /src/Render/IndexRenderer.php
 */

use App\Render\IndexRenderer;

function index_page_css(): string
{
    return IndexRenderer::pageCss();
}

function render_index_tutorial(): string
{
    return IndexRenderer::tutorial();
}

function render_index_welcome_state(array $welcome_forms): string
{
    return IndexRenderer::welcomeState($welcome_forms);
}

function render_index_hero(): string
{
    return IndexRenderer::hero();
}

function render_index_where_am_i(): string
{
    return IndexRenderer::whereAmI();
}

function render_index_quick_stats_validator(int $my_pending): string
{
    return IndexRenderer::quickStatsValidator($my_pending);
}

function render_index_quick_stats_admin(array $admin_stats): string
{
    return IndexRenderer::quickStatsAdmin($admin_stats);
}

function render_index_quick_stats_agent(int $my_total, int $my_en_cours, int $my_valide): string
{
    return IndexRenderer::quickStatsAgent($my_total, $my_en_cours, $my_valide);
}

function render_index_form_cards(array $active_forms): string
{
    return IndexRenderer::formCards($active_forms);
}

function render_index_nav_tiles(bool $is_admin, bool $has_owned, array $owned_forms): string
{
    return IndexRenderer::navTiles($is_admin, $has_owned, $owned_forms);
}

function render_index_owner_forms(array $owned_forms): string
{
    return IndexRenderer::ownerForms($owned_forms);
}

function render_index_tooltips_script(): string
{
    return IndexRenderer::tooltipsScript();
}
