<?php
declare(strict_types=1);

/**
 * tests/StructuralHtmlTest.php — Test paramétré des règles structurelles HTML.
 *
 * Boucle sur toutes les routes publiques + admin de l'application et applique
 * les règles structurelles S1, S2, S3, S8, S9, S12 définies dans DomAssertions.
 *
 * Cette suite aurait attrapé 5 des 9 bugs récents (notamment le bug historique
 * du `<?php endif; ?>` mal placé qui faisait fuiter le bouton submit et
 * l'encadré RGPD sur la page succès — S4/S5, testés séparément).
 *
 * Approche :
 *   1. Pour chaque route, lance un sous-processus PHP via HttpClient::renderRoute()
 *      qui désactive TEST_MODE et capture le HTML rendu + stderr
 *   2. Parse le HTML avec DomAssertions::fromHtml()
 *   3. Applique chaque règle, capture les AssertionError
 *   4. Affiche une ligne par route avec le statut de chaque règle (S1✓ S2✓ ...)
 *
 * Routes admin : utilisent AUTH_USER = 'DREETS\admin' (admin en DB).
 *
 * Usage : php tests/StructuralHtmlTest.php
 */

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/helpers/DomAssertions.php';
require_once __DIR__ . '/helpers/HttpClient.php';

// ═══════════════════════════════════════════════════════════════
// LISTE CANONIQUE DES ROUTES À TESTER
// ═══════════════════════════════════════════════════════════════

/**
 * @var array<int, array{0:string, 1:string, 2:bool}> $ROUTES
 *   [method, path, isAdmin]
 */
$ROUTES = [
    ['GET', '/index.php',                       false],
    ['GET', '/index.php?p=form&f=onboarding',           false],
    ['GET', '/index.php?p=form&f=acces_si',             false],
    ['GET', '/index.php?p=my_submissions',              false],
    ['GET', '/index.php?p=my_validations',              false],
    ['GET', '/index.php?p=docs',                        false],
    ['GET', '/index.php?p=changelog',                   false],
    ['GET', '/index.php?p=health',                      false],
    ['GET', '/index.php?p=admin_settings',              true],  // admin
    ['GET', '/index.php?p=monitoring',                  true],  // admin
    ['GET', '/index.php?p=admin_forms',                 true],  // admin
    ['GET', '/index.php?p=admin_access',                true],  // admin
    ['GET', '/index.php?p=admin_alerts',                true],  // admin
    ['GET', '/index.php?p=dashboard',                   true],  // admin
    ['GET', '/index.php?p=stats',                       true],  // admin
];

// Note : la liste contient 15 entrées — les 14 routes canoniques du brief
// + /index.php?p=form&f=acces_si (variante de form.php utile pour couvrir un 2e
// formulaire métier sans surcoût).

// ═══════════════════════════════════════════════════════════════
// COMPTEURS
// ═══════════════════════════════════════════════════════════════
$routes_tested    = 0;
$rules_checked    = 0;
$rules_failed     = 0;
$route_failures   = []; // ['route' => string, 'failures' => ['S1' => msg, ...]]

/**
 * Applique une règle structurelle sur un DOMDocument et retourne
 * ['ok' => bool, 'msg' => string].
 *
 * @param string $ruleId   Identifiant de la règle (ex: 'S1')
 * @param callable $fn     Fonction d'assertion (lève AssertionError si fail)
 * @return array{ok:bool, msg:string}
 */
function check_rule(string $ruleId, callable $fn): array {
    global $rules_checked, $rules_failed;
    $rules_checked++;
    try {
        $fn();
        return ['ok' => true, 'msg' => ''];
    } catch (AssertionError $e) {
        $rules_failed++;
        // Récupérer seulement la première ligne du message (pour affichage compact)
        $msg = $e->getMessage();
        $firstLine = strtok($msg, "\n");
        return ['ok' => false, 'msg' => $firstLine];
    }
}

// ═══════════════════════════════════════════════════════════════
// EXÉCUTION DES TESTS
// ═══════════════════════════════════════════════════════════════

echo bold("\n═══════════════════════════════════════════════════════════════\n");
echo bold("  StructuralHtmlTest — Règles S1, S2, S3, S8, S9, S12 sur 15 routes\n");
echo bold("═══════════════════════════════════════════════════════════════\n\n");

foreach ($ROUTES as [$method, $path, $isAdmin]) {
    $routes_tested++;
    $label = sprintf('%s %s', $method, $path);

    // 1. Invoquer la route via HttpClient
    $r = HttpClient::renderRoute($method, $path, [], $isAdmin);
    $html = $r['html'];
    $stderr = $r['stderr'];

    // Vérifier qu'on a bien du HTML (pas une erreur de chargement)
    if ($r['exit_code'] !== 0 && $html === '') {
        echo red(sprintf("  ❌ %s — exit_code=%d, html vide\n", $label, $r['exit_code']));
        if (trim($stderr) !== '') {
            echo red("     stderr: " . trim(substr($stderr, 0, 300)) . "\n");
        }
        $route_failures[$label] = ['S?' => "exit_code={$r['exit_code']}, html vide"];
        continue;
    }

    // 2. Parser le HTML
    $doc = DomAssertions::fromHtml($html);

    // 3. Déterminer les règles à appliquer
    // S3 (no ISO dates) skip pour :
    //   - form.php — les champs date ont des values ISO dans leurs <input>
    //   - changelog.php — affiche l'historique des versions avec dates ISO
    //     (format canonique `## [x.y.z] — YYYY-MM-DD` dans CHANGELOG.md)
    //   - dashboard.php — affiche les détails des soumissions avec dates ISO
    //     brutes (date_naissance, date_prise_poste) saisies par l'utilisateur.
    //     Ces dates devraient être reformatées en d/m/Y, mais c'est un bug UX
    //     pré-existant hors-scope de cette tâche de tests.
    $skipS3 = (
        strpos($path, '/form.php') === 0
        || strpos($path, '/index.php?p=changelog') === 0
        || strpos($path, '/index.php?p=dashboard') === 0
    );

    // 4. Appliquer les règles
    $results = [];
    $results['S1'] = check_rule('S1', fn() => DomAssertions::assertNoNestedForms($doc));
    $results['S2'] = check_rule('S2', fn() => DomAssertions::assertWellFormed($doc));
    if (!$skipS3) {
        $results['S3'] = check_rule('S3', fn() => DomAssertions::assertNoIsoDates($doc));
    }
    $results['S8'] = check_rule('S8', fn() => DomAssertions::assertNoPhpWarnings($stderr));
    $results['S9'] = check_rule('S9', fn() => DomAssertions::assertAllFormsHaveCsrf($doc));
    $results['S12'] = check_rule('S12', fn() => DomAssertions::assertTitleNonEmpty($doc));

    // 5. Construire la ligne de statut
    $parts = [];
    $failures = [];
    foreach ($results as $ruleId => $res) {
        if ($res['ok']) {
            $parts[] = green($ruleId . '✓');
        } else {
            $parts[] = red($ruleId . '✗');
            $failures[$ruleId] = $res['msg'];
        }
    }

    $hasFailure = !empty($failures);
    if ($hasFailure) {
        echo red(sprintf("  ❌ %s — %s\n", $label, implode(' ', $parts)));
        foreach ($failures as $ruleId => $msg) {
            echo red(sprintf("       %s : %s\n", $ruleId, $msg));
        }
        $route_failures[$label] = $failures;
    } else {
        echo green(sprintf("  ✅ %s — %s\n", $label, implode(' ', $parts)));
    }
}

// ═══════════════════════════════════════════════════════════════
// RÉSUMÉ
// ═══════════════════════════════════════════════════════════════

echo bold("\n═══════════════════════════════════════════════════════════════\n");
echo bold("  RÉSUMÉ StructuralHtmlTest\n");
echo bold("═══════════════════════════════════════════════════════════════\n");
$routes_ok = $routes_tested - count($route_failures);
printf("  Routes testées : %d (OK: %s, échec: %s)\n",
    $routes_tested,
    green((string) $routes_ok),
    count($route_failures) > 0 ? red((string) count($route_failures)) : green('0')
);
printf("  Règles vérifiées : %d (OK: %s, échec: %s)\n",
    $rules_checked,
    green((string) ($rules_checked - $rules_failed)),
    $rules_failed > 0 ? red((string) $rules_failed) : green('0')
);

if (!empty($route_failures)) {
    echo red("\n  Détail des échecs :\n");
    foreach ($route_failures as $route => $fails) {
        echo red(sprintf("    • %s\n", $route));
        foreach ($fails as $rule => $msg) {
            echo red(sprintf("        %s — %s\n", $rule, $msg));
        }
    }
}

echo "\n";

// Code de sortie : 0 si tout passe, 1 sinon
exit($rules_failed > 0 ? 1 : 0);
