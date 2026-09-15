<?php
declare(strict_types=1);

/**
 * phpstan-test-functions.php — Stub pour l'analyse statique de tests/.
 *
 * Déclare les fonctions procédurales appelées par les scripts de test
 * historiques restants (tests/test_advanced_*.php) mais dont les vraies
 * définitions n'existent plus dans le code actuel — supprimées lors de
 * migrations antérieures (wrappers procéduraux vers DI, refactor des
 * fonctions render_xxx / save_draft / build_url vers des classes
 * Renderer et Repository OOP). Ces scripts ne
 * sont câblés dans aucun job CI (voir circuitdemat, session 2026-07-29) :
 * ils crasheraient réellement s'ils étaient exécutés. Ce stub sert
 * uniquement à ce que PHPStan puisse analyser statiquement le reste de
 * ces fichiers sans un mur de "Function xxx not found" qui masquerait
 * les vraies erreurs à côté — il ne doit JAMAIS être chargé en dehors
 * de l'analyse PHPStan (jamais require par du code applicatif).
 *
 * Signatures des fonctions de tests/test_bootstrap.php recopiées à
 * l'identique (vérifié par lecture, 2026-07-29). Bootstraper
 * test_bootstrap.php directement a des effets de bord (démarre une
 * session via helpers.php → lib/core_bootstrap.php) qui font planter
 * l'analyse — d'où ce stub plutôt qu'un require direct.
 */

// ── tests/test_bootstrap.php (couleurs terminal + micro-framework) ──
if (!function_exists('green')) { function green(string $t): string { return $t; } }
if (!function_exists('red')) { function red(string $t): string { return $t; } }
if (!function_exists('yellow')) { function yellow(string $t): string { return $t; } }
if (!function_exists('cyan')) { function cyan(string $t): string { return $t; } }
if (!function_exists('bold')) { function bold(string $t): string { return $t; } }
if (!function_exists('reset_color')) { function reset_color(): string { return ''; } }
if (!function_exists('test')) { function test(string $name, callable $fn): void {} }
if (!function_exists('assert_test')) { function assert_test(string $name, bool $condition, string $fail_msg = ''): void {} }
if (!function_exists('capture_output')) { function capture_output(callable $fn): string { return ''; } }
if (!function_exists('print_test_summary')) { function print_test_summary(string $title = 'RÉSULTATS'): int { return 0; } }
if (!function_exists('test_temp_dir')) { function test_temp_dir(): string { return ''; } }
if (!function_exists('kill_port')) { function kill_port(int $port): void {} }

// ── Fonctions render_*/draft_*/url_* disparues (refactor OOP antérieur,
//    présence confirmée nulle part dans src/lib/classes/helpers.php le
//    2026-07-29). Appelées par des scripts sans lien avec aucun job CI. ──
// D6/D7 (2026-09-15) : seul render_field() conserve un appelant dans
// tests/ — test_advanced_edge_email_stats.php. Les 18 autres stubs
// (render_page, render_messages, render_nav, render_form_progress_indicator,
// render_breadcrumb, render_favicon, render_search_bar, render_status_filter,
// render_submission_data, save_draft, get_draft, delete_draft, list_drafts,
// cleanup_old_drafts, build_url, get_app_name, parse_changelog,
// persona_rewrite_urls) ont été élagués : leurs seuls appelants
// (test_unit_render_data.php, test_unit_nav_utils.php, test_unit_wave6.php,
// test_unit_wave8_9.php, test_coverage_gaps.php, test_persona_token.php)
// font partie des fichiers supprimés par D6/D7.
if (!function_exists('render_field')) { function render_field(...$args): string { return ''; } }
