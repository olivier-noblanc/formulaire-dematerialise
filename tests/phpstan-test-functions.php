<?php
declare(strict_types=1);

/**
 * phpstan-test-functions.php — Stub pour l'analyse statique de tests/.
 *
 * Déclare les fonctions procédurales appelées par des scripts de test
 * historiques (tests/test_advanced_*.php, test_unit_wave*.php,
 * test_unit_*.php, test_persona_token.php...) mais dont les vraies
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
if (!function_exists('render_page')) { function render_page(...$args): string { return ''; } }
if (!function_exists('render_field')) { function render_field(...$args): string { return ''; } }
if (!function_exists('render_messages')) { function render_messages(...$args): string { return ''; } }
if (!function_exists('render_nav')) { function render_nav(...$args): string { return ''; } }
if (!function_exists('render_form_progress_indicator')) { function render_form_progress_indicator(...$args): string { return ''; } }
if (!function_exists('render_breadcrumb')) { function render_breadcrumb(...$args): string { return ''; } }
if (!function_exists('render_favicon')) { function render_favicon(...$args): string { return ''; } }
if (!function_exists('render_search_bar')) { function render_search_bar(...$args): string { return ''; } }
if (!function_exists('render_status_filter')) { function render_status_filter(...$args): string { return ''; } }
if (!function_exists('render_submission_data')) { function render_submission_data(...$args): string { return ''; } }
if (!function_exists('save_draft')) { function save_draft(...$args): void {} }
if (!function_exists('get_draft')) { function get_draft(...$args): ?array { return null; } }
if (!function_exists('delete_draft')) { function delete_draft(...$args): void {} }
if (!function_exists('list_drafts')) { function list_drafts(...$args): array { return []; } }
if (!function_exists('cleanup_old_drafts')) { function cleanup_old_drafts(...$args): void {} }
if (!function_exists('build_url')) { function build_url(...$args): string { return ''; } }
if (!function_exists('get_app_name')) { function get_app_name(...$args): string { return ''; } }
if (!function_exists('parse_changelog')) { function parse_changelog(...$args): array { return []; } }
if (!function_exists('persona_rewrite_urls')) { function persona_rewrite_urls(...$args): string { return ''; } }
