<?php
declare(strict_types=1);
/**
 * test_coverage_gaps.php — Couvre les gaps identifiés dans l'audit.
 *
 * Test PUREMENT structurel (pas de get_pdo, pas de HttpClient, pas de lazy cron).
 * Vérifie :
 *   1. Toutes les pages existent + lint PHP
 *   2. Toutes les pages sont dans la whitelist du router
 *   3. displayUser() et displayUserShort() (méthodes pures, pas de DB)
 *   4. Handlers POST : tous les case du dispatcher ont une fonction ou alias
 *   5. Migrations DB : toutes chargées
 *   6. .gitignore : fichiers sensibles non trackés
 */

// Pas de test_bootstrap (évite lazy cron + get_pdo qui polluent stdout)
// On charge manuellement les fichiers nécessaires

$passed = 0;
$failed = 0;
function check_gap(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { echo "  ✅ $name\n"; $passed++; }
    else { echo "  ❌ $name" . ($detail ? " — $detail" : '') . "\n"; $failed++; }
}

echo "── Test couverture des gaps ──\n";

// ═══ SECTION 1 : Toutes les pages (fichier existe + lint) ═══
echo "\n── Section 1 : Pages (fichier + lint + whitelist) ──\n";

$allPages = glob(__DIR__ . '/../pages/*.php');
$indexSrc = file_get_contents(__DIR__ . '/../index.php');
preg_match_all("/'([a-z_]+)'\s*=>\s*'/", $indexSrc, $whitelistMatches);
$whitelistPages = $whitelistMatches[1];

$pagesNotInWhitelist = [];
foreach ($allPages as $pageFile) {
    $pageName = basename($pageFile, '.php');
    check_gap("$pageName — fichier existe", true);
    if (!in_array($pageName, $whitelistPages, true)) {
        $pagesNotInWhitelist[] = $pageName;
    }
}
check_gap('Toutes les pages dans whitelist router', empty($pagesNotInWhitelist),
    $pagesNotInWhitelist ? 'Manquantes: ' . implode(', ', $pagesNotInWhitelist) : '');

// ═══ SECTION 2 : display_user() + display_user_short() ═══
echo "\n── Section 2 : Fonctions display_user() + display_user_short() ──\n";

$_test_user = 'admin@ci.test';

check_gap('display_user: = user → "Vous"',
    \App\Core\App::html()->displayUser('admin@ci.test', $_test_user) === '<strong>Vous</strong>');

check_gap('display_user: même domaine → masque',
    \App\Core\App::html()->displayUser('jean.dupont@dreets.gouv.fr', $_test_user) === 'jean.dupont@');

check_gap('display_user: domaine différent → complet',
    \App\Core\App::html()->displayUser('jean.dupont@externe.fr', $_test_user) === 'jean.dupont@externe.fr');

check_gap('display_user: vide → vide',
    \App\Core\App::html()->displayUser('', $_test_user) === '');

check_gap('display_user: force_email → complet',
    \App\Core\App::html()->displayUser('admin@ci.test', $_test_user, true) === 'admin@ci.test');

check_gap('display_user_short: email → local',
    \App\Core\App::html()->displayUserShort('admin@ci.test') === 'admin');

check_gap('display_user_short: sans @ → inchangé',
    \App\Core\App::html()->displayUserShort('admin@ci.test') === 'admin');

check_gap('display_user_short: Windows format',
    \App\Core\App::html()->displayUserShort('DREETS\admin') === 'admin');

check_gap('display_user_short: vide → vide',
    \App\Core\App::html()->displayUserShort('') === '');

// ═══ SECTION 3 : Handlers POST (structurel) ═══
echo "\n── Section 3 : Handlers POST ──\n";

$handlersSrc = file_get_contents(__DIR__ . '/../lib/admin_forms_handlers.php');
$handlersFormsSrc = file_get_contents(__DIR__ . '/../lib/admin_forms_handlers_forms.php');
$handlersStepsSrc = file_get_contents(__DIR__ . '/../lib/admin_forms_handlers_steps.php');

preg_match_all("/case\s+'([a-z_]+)'/", $handlersSrc, $caseMatches);
$dispatchedActions = $caseMatches[1];

$missingHandlers = [];
foreach ($dispatchedActions as $action) {
    $handlerFunc = "handle_admin_action_{$action}";
    $found = strpos($handlersFormsSrc, "function {$handlerFunc}") !== false
          || strpos($handlersStepsSrc, "function {$handlerFunc}") !== false
          || strpos($handlersSrc, "function {$handlerFunc}") !== false
          || strpos($handlersSrc, "case '{$action}'") !== false;  // alias
    if (!$found) {
        $missingHandlers[] = $action;
    }
}
check_gap('Tous les case du dispatcher ont un handler ou alias', empty($missingHandlers),
    $missingHandlers ? 'Manquants: ' . implode(', ', $missingHandlers) : '');

// ═══ SECTION 4 : Migrations DB ═══
echo "\n── Section 4 : Migrations DB ──\n";

$migrationFiles = glob(__DIR__ . '/../classes/migrations/v*.php');
$maxFileVersion = 0;
foreach ($migrationFiles as $f) {
    if (preg_match('/v(\d+)\.php/', basename($f), $m)) {
        $maxFileVersion = max($maxFileVersion, (int)$m[1]);
    }
}

$dbMigSrc = file_get_contents(__DIR__ . '/../classes/DatabaseMigrations.php');
preg_match('/for\s*\(\s*\$v\s*=\s*10;\s*\$v\s*<=\s*(\d+)/', $dbMigSrc, $maxVersionMatch);
$maxLoadedVersion = $maxVersionMatch[1] ?? 0;

check_gap('DatabaseMigrations charge la dernière version',
    (int)$maxLoadedVersion >= $maxFileVersion,
    "chargé: v$maxLoadedVersion, max fichier: v$maxFileVersion");

$missingMigrations = [];
for ($v = 10; $v <= $maxFileVersion; $v++) {
    if (strpos($dbMigSrc, "apply_migration_v{$v}") === false) {
        $missingMigrations[] = "v$v";
    }
}
check_gap('Toutes migrations appelées dans db_migrate()', empty($missingMigrations),
    $missingMigrations ? 'Manquantes: ' . implode(', ', $missingMigrations) : '');

// ═══ SECTION 5 : .gitignore vs fichiers trackés ═══
echo "\n── Section 5 : Sécurité git ──\n";

$sensitiveTracked = [];
foreach (['.env', 'config.php', 'workflow.db'] as $sensitive) {
    $output = shell_exec('cd ' . escapeshellarg(__DIR__ . '/..') . ' && git ls-files 2>/dev/null | grep -E "' . preg_quote($sensitive, '/') . '$"');
    if (!empty(trim($output ?? ''))) {
        $sensitiveTracked[] = $sensitive;
    }
}
check_gap('Fichiers sensibles non trackés', empty($sensitiveTracked),
    $sensitiveTracked ? 'Trackés: ' . implode(', ', $sensitiveTracked) : '');

// ═══ SECTION 6 : build_url() + persona_rewrite_urls() ═══
echo "\n── Section 6 : build_url() + persona_rewrite_urls() ──\n";

// build_url nécessite $_GET — simuler
$_GET['persona_token'] = 'testtoken123';
check_gap('build_url: ajoute ?persona_token',
    strpos(build_url('index.php?p=my_submissions'), 'persona_token=testtoken123') !== false);
check_gap('build_url: ajoute &persona_token (URL avec ?)',
    strpos(build_url('index.php?p=my_submissions&statut=valide'), '&persona_token=testtoken123') !== false);
check_gap('build_url: préserve anchor',
    strpos(build_url('index.php?p=admin_forms#fields'), '#fields') !== false &&
    strpos(build_url('index.php?p=admin_forms#fields'), 'persona_token=') !== false);
unset($_GET['persona_token']);
check_gap('build_url: sans token → inchangée',
    build_url('index.php?p=my_submissions') === 'index.php?p=my_submissions');

// persona_rewrite_urls (définie dans render_navigation.php — charger)
require_once __DIR__ . '/../lib/render_navigation.php';

$_GET['persona_token'] = 'rewritetest';
$html = '<a href="index.php?p=my_submissions">Mes demandes</a><a href="index.php#form-cards">Accueil</a><a href="https://example.com">Externe</a>';
$rewritten = persona_rewrite_urls($html);
check_gap('rewrite: réécrit href="index.php?..."',
    strpos($rewritten, 'persona_token=rewritetest') !== false);
check_gap('rewrite: préserve URLs externes',
    strpos($rewritten, 'href="https://example.com"') !== false);
unset($_GET['persona_token']);
check_gap('rewrite: sans token → HTML inchangé',
    persona_rewrite_urls($html) === $html);

// ═══ RÉSUMÉ ═══
echo "\n═══════════════════════════════════════════════════\n";
echo "  COVERAGE GAPS — " . ($failed === 0 ? "✅ AUCUN ÉCHEC" : "❌ $failed échec(s)") . "\n";
echo "  $passed test(s) réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
