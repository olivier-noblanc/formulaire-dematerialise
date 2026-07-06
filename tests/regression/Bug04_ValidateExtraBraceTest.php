<?php
declare(strict_types=1);
/**
 * Bug 04 — validate.php extra `}` (P0)
 *
 * Symptôme historique : un `}` en trop à la ligne 22 fermait le bloc POST
 * prématurément → warnings « undefined variable » + « Données invalides »
 * permanent (même sur un simple GET sans token).
 *
 * Cause : accolade fermante surnuméraire dans le bloc POST de validate.php
 *         qui court-circuitait toute la logique de GET.
 *
 * Test minimal : GET /validate.php SANS token → la page doit afficher
 * « Lien invalide » (et NON « Données invalides »), et stderr doit être
 * vide de warnings PHP « Undefined variable » ou « Undefined array key ».
 *
 * Fichier : tests/regression/Bug04_ValidateExtraBraceTest.php
 *
 * @package tests\regression
 */

require_once __DIR__ . '/_subprocess_helper.php';

/**
 * Lance le test de non-régression Bug 04.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug04_test(): bool {
    // Utilisateur lambda (pas besoin d'être admin pour GET /validate.php).
    $user = 'DREETS\bug04-testeur';

    $script_body = <<<'PHP'
require_once $project_root . "/helpers.php";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = "/validate.php";
$_SERVER["SCRIPT_NAME"]    = "/validate.php";
// Aucun $_GET["token"] → doit tomber sur le cas « Lien invalide »

ob_start();
try {
    require $project_root . "/pages/validate.php";
    $html = ob_get_clean();
} catch (\Throwable $e) {
    $html = ob_get_clean() . "\n__EXCEPTION__:" . $e->getMessage() . "\n" . $e->getTraceAsString();
}

echo $html;
PHP;

    $r = run_regression_script(
        $script_body,
        [
            'AUTH_USER'      => $user,
            'REQUEST_METHOD' => 'GET',
        ]
    );

    $html = $r['stdout'];
    $stderr = $r['stderr'];

    // Vérifier qu'il n'y a pas d'exception
    if (strpos($html, '__EXCEPTION__') !== false) {
        echo "  ❌ Bug04 — Une exception a été levée pendant le rendu de validate.php\n";
        echo "     " . substr($html, strpos($html, '__EXCEPTION__'), 1500) . "\n";
        return false;
    }

    // Assertion 1 : la page doit contenir « Lien invalide »
    if (strpos($html, 'Lien invalide') === false) {
        echo "  ❌ Bug04 — La page ne contient pas « Lien invalide » — le bug du `}` en trop a pu réapparaître\n";
        echo "     HTML (extrait) : " . substr($html, 0, 1000) . "\n";
        return false;
    }

    // Assertion 2 : la page NE doit PAS contenir « Données invalides »
    if (strpos($html, 'Données invalides') !== false) {
        echo "  ❌ Bug04 — La page affiche « Données invalides » sur un GET sans token — bug du `}` en trop réapparu\n";
        echo "     HTML (extrait) : " . substr($html, 0, 1000) . "\n";
        return false;
    }

    // Assertion 3 : stderr ne doit PAS contenir de warnings « Undefined »
    // (le bug historique générait des « Undefined variable $token » etc.)
    // On exclut explicitement la deprecation « session.use_only_cookies »
    // qui est un problème pré-existant PHP 8.4 SANS RAPPORT avec ce bug.
    $stderr_filtered = preg_replace(
        '/PHP Deprecated:.*session\.use_only_cookies.*/',
        '',
        $stderr
    );
    if (preg_match('/(Warning|Notice|Deprecated).*Undefined/', $stderr_filtered)) {
        echo "  ❌ Bug04 — stderr contient un warning « Undefined » — bug du `}` en trop réapparu\n";
        echo "     STDERR : " . $stderr . "\n";
        return false;
    }

    echo "  ✅ Bug04 — GET /validate.php sans token affiche « Lien invalide » (pas « Données invalides ») + aucun warning « Undefined »\n";
    return true;
}
