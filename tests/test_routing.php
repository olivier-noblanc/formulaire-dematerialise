<?php
declare(strict_types=1);
/**
 * test_routing.php — Test exhaustif du front controller (toutes les routes).
 *
 * Utilise l'approche subprocess (comme StructuralHtmlTest) pour fonctionner
 * sans serveur PHP -S (qui ne charge pas les extensions dans certains envs).
 *
 * Vérifie :
 *   1. Chaque route de la whitelist retourne du HTML valide (HTTP 200 équiv)
 *   2. <title> non vide
 *   3. <link> vers assets.php présent
 *   4. body class page-xxx présent
 *   5. Paramètres préservés (tab=pending, period=week, f=onboarding)
 *   6. Pages inexistantes → 404
 *   7. Pas de liens cassés (href="xxx.php" ou href="?xxx")
 *
 * Usage : php tests/test_routing.php
 */

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/helpers/HttpClient.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failed++;
    }
}

// ── Test 1 : Toutes les routes de la whitelist ──
echo "── Test 1 : Toutes les routes du router (HTML + title + CSS + body class) ──\n";

$routes = [
    'accueil'        => ['need_admin' => false, 'params' => ''],
    'form'           => ['need_admin' => false, 'params' => '&f=onboarding'],
    'my_submissions' => ['need_admin' => false, 'params' => ''],
    'my_validations' => ['need_admin' => false, 'params' => ''],
    'docs'           => ['need_admin' => false, 'params' => ''],
    'changelog'      => ['need_admin' => false, 'params' => ''],
    'rgpd'           => ['need_admin' => true, 'params' => ''],
    'admin_access'   => ['need_admin' => true, 'params' => ''],
    'admin_alerts'   => ['need_admin' => true, 'params' => ''],
    'admin_forms'    => ['need_admin' => true, 'params' => ''],
    'admin_settings' => ['need_admin' => true, 'params' => ''],
    'dashboard'      => ['need_admin' => true, 'params' => ''],
    'monitoring'     => ['need_admin' => true, 'params' => ''],
    'stats'          => ['need_admin' => true, 'params' => ''],
    'health'         => ['need_admin' => true, 'params' => ''],
    'backup'         => ['need_admin' => true, 'params' => ''],
];

foreach ($routes as $page => $config) {
    $path = "/index.php?p=$page" . $config['params'];
    $isAdmin = $config['need_admin'];
    $result = \HttpClient::renderRoute('GET', $path, [], $isAdmin);
    $html = $result['html'] ?? '';
    $stderr = $result['stderr'] ?? '';

    // HTML non vide
    $htmlOk = strlen($html) > 100;
    check("$page → HTML non vide", $htmlOk, $htmlOk ? '' : 'html vide, stderr=' . substr($stderr, 0, 200));

    if (!$htmlOk) continue;

    // <title> non vide
    preg_match('/<title>([^<]*)<\/title>/', $html, $titleMatch);
    $titleOk = !empty($titleMatch[1]) && trim($titleMatch[1]) !== '';
    check("$page → <title> non vide", $titleOk, $titleOk ? '' : 'titre vide');

    // <link> vers assets.php
    $hasLink = str_contains($html, 'assets.php?type=css');
    check("$page → <link> vers assets.php", $hasLink);

    // body class page-xxx
    $hasClass = str_contains($html, 'class="page-');
    check("$page → body class page-xxx", $hasClass);

    // Pas "Fatal" dans le HTML
    $hasFatal = stripos($html, 'Fatal error') !== false || stripos($html, 'Erreur interne') !== false;
    check("$page → pas d'erreur fatale", !$hasFatal, $hasFatal ? 'Fatal error détecté' : '');
}

// ── Test 2 : Paramètres préservés ──
echo "\n── Test 2 : Paramètres préservés dans les URLs ──\n";

// my_validations&tab=pending → onglet pending actif
$result = \HttpClient::renderRoute('GET', '/index.php?p=my_validations&tab=pending', [], false);
$pendingActive = str_contains($result['html'] ?? '', 'En attente')
              && str_contains($result['html'] ?? '', 'stat warning active');
check('my_validations&tab=pending → "En attente" + stat active', $pendingActive);

// my_validations&tab=done → onglet done
$result = \HttpClient::renderRoute('GET', '/index.php?p=my_validations&tab=done', [], false);
$doneActive = str_contains($result['html'] ?? '', 'Historique');
check('my_validations&tab=done → "Historique" présent', $doneActive);

// form&f=onboarding → formulaire
$result = \HttpClient::renderRoute('GET', '/index.php?p=form&f=onboarding', [], false);
$hasForm = str_contains($result['html'] ?? '', 'id="form-main"');
check('form&f=onboarding → <form id="form-main">', $hasForm);

$hasRgpd = str_contains($result['html'] ?? '', 'rgpd_consent');
check('form&f=onboarding → checkbox RGPD', $hasRgpd);

// ── Test 3 : Pages inexistantes → 404 ──
echo "\n── Test 3 : Pages inexistantes → 404 ──\n";

$result = \HttpClient::renderRoute('GET', '/index.php?p=nonexistent', [], false);
$has404 = str_contains($result['html'] ?? '', 'Page introuvable');
check('?p=nonexistent → "Page introuvable"', $has404);

$result = \HttpClient::renderRoute('GET', '/index.php?p=admin_secret', [], false);
$has404Secret = str_contains($result['html'] ?? '', 'Page introuvable');
check('?p=admin_secret → "Page introuvable" (pas dans whitelist)', $has404Secret);

// ── Test 4 : Pas de liens cassés ──
echo "\n── Test 4 : Pas de liens cassés dans le HTML rendu ──\n";

$pagesToCheck = ['accueil', 'my_submissions', 'my_validations', 'admin_settings', 'admin_forms', 'dashboard', 'monitoring'];
$brokenLinks = 0;
$brokenDetails = [];

foreach ($pagesToCheck as $page) {
    $isAdmin = in_array($page, ['admin_settings', 'admin_forms', 'dashboard', 'monitoring']);
    $result = \HttpClient::renderRoute('GET', "/index.php?p=$page", [], $isAdmin);
    $html = $result['html'] ?? '';

    // href="xxx.php" (sans index.php, assets.php, install.php)
    if (preg_match_all('/href="([a-z_]+\.php)"/i', $html, $matches)) {
        foreach ($matches[1] as $link) {
            if (!in_array($link, ['index.php', 'assets.php', 'install.php'])) {
                $brokenLinks++;
                $brokenDetails[] = "$page: href=\"$link\"";
            }
        }
    }
    // href="?xxx" (liens relatifs qui perdent p=)
    if (preg_match_all('/href="\?([a-z]+)/i', $html, $matches2)) {
        foreach ($matches2[1] as $link) {
            $brokenLinks++;
            $brokenDetails[] = "$page: href=\"?$link\"";
        }
    }
    // index.php?p=xxx?yyy (? au lieu de &)
    if (preg_match_all('/index\.php\?p=[a-z_]+\?[a-z]/i', $html, $matches3)) {
        foreach ($matches3[0] as $badUrl) {
            $brokenLinks++;
            $brokenDetails[] = "$page: $badUrl (? au lieu de &)";
        }
    }
}

check('Aucun lien cassé (href="xxx.php", href="?xxx", ou ? au lieu de &)', $brokenLinks === 0,
    $brokenLinks > 0 ? implode('; ', array_slice($brokenDetails, 0, 5)) : '');

// ── Test 5 : index.php sans ?p= → accueil ──
echo "\n── Test 5 : index.php sans ?p= → accueil ──\n";

$result = \HttpClient::renderRoute('GET', '/index.php', [], false);
$hasAccueil = str_contains($result['html'] ?? '', 'CircuitDémat')
           || str_contains($result['html'] ?? '', 'Accueil');
check('index.php sans ?p= → page accueil', $hasAccueil);

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSULTATS : $passed réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
// Contrat B-HARNESS : ce script utilise les compteurs du bootstrap mais
// imprime son propre résumé — poser le flag pour que le filet anti-masquage
// ne force pas exit(1) après un run nominal.
$GLOBALS['_test_summary_printed'] = true;
exit($failed > 0 ? 1 : 0);
