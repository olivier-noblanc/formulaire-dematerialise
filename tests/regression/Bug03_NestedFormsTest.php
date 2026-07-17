<?php
declare(strict_types=1);
/**
 * Bug 03 — Forms imbriqués dans admin_settings (P0)
 *
 * Symptôme historique : `<form>` externe (Enregistrer) et `<form>` interne
 * (Tester le webhook) imbriqués → HTML invalide. Le navigateur fermait
 * prématurément le `<form>` externe, et le bouton « Tester le webhook »
 * ne soumettait plus rien.
 *
 * Cause : dans lib/render_admin_settings.php (section Webhooks), le
 *         `<form>` « Enregistrer » englobait le `<form>` « Tester le
 *         webhook » au lieu d'être refermé avant.
 *
 * Test minimal : GET /index.php?p=admin_settings en tant qu'admin → parser le HTML
 * avec DOMDocument → assert qu'aucun `<form>` n'est ancêtre d'un autre
 * `<form>` (XPath `//form//form` doit retourner 0 nœuds).
 *
 * Fichier : tests/regression/Bug03_NestedFormsTest.php
 *
 * @package tests\regression
 */

require_once __DIR__ . '/_subprocess_helper.php';

/**
 * Lance le test de non-régression Bug 03.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug03_test(): bool {
    // Admin principal en DB : olivier.noblanc@dreets.gouv.fr
    // (doit être admin pour passer require_admin() dans admin_settings.php)
    $admin_email = 'olivier.noblanc@dreets.gouv.fr';

    // Corps du sous-processus : on inclut admin_settings.php comme le
    // ferait un require en tête de page, en capturant le HTML rendu.
    // Note : admin_settings.php fait `echo render_page(...)` — on capture
    // ce echo via ob_start.
    $script_body = <<<'PHP'
require_once $project_root . "/helpers.php";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = "/index.php?p=admin_settings";
$_SERVER["SCRIPT_NAME"]    = "/index.php?p=admin_settings";

ob_start();
try {
    // admin_settings.php migré vers AdminSettingsController — l'utilisateur
    // courant doit être admin. On s'est arrangé pour que AUTH_USER pointe
    // sur l'admin principal.
    $controller = new \App\Controller\AdminSettingsController();
    $controller->handle();
    $html = ob_get_clean();
} catch (\Throwable $e) {
    $html = ob_get_clean() . "\n__EXCEPTION__:" . $e->getMessage() . "\n" . $e->getTraceAsString();
}

echo $html;
PHP;

    $r = run_regression_script(
        $script_body,
        [
            'AUTH_USER'      => $admin_email,
            'REQUEST_METHOD' => 'GET',
        ]
    );

    $html = $r['stdout'];

    // Vérifier qu'il n'y a pas d'exception
    if (strpos($html, '__EXCEPTION__') !== false) {
        echo "  ❌ Bug03 — Une exception a été levée pendant le rendu de admin_settings.php\n";
        echo "     " . substr($html, strpos($html, '__EXCEPTION__'), 1500) . "\n";
        if (!empty($r['stderr'])) {
            echo "     STDERR : " . substr($r['stderr'], 0, 600) . "\n";
        }
        return false;
    }

    // Vérifier qu'on a bien atteint la page (au moins un <form>)
    if (strpos($html, '<form') === false) {
        echo "  ❌ Bug03 — Aucun <form> trouvé dans le HTML rendu. Probablement un accès refusé ou une erreur.\n";
        echo "     HTML (extrait) : " . substr($html, 0, 1000) . "\n";
        return false;
    }

    // Parser le HTML avec DOMDocument
    $dom = new \DOMDocument();
    // @ pour supprimer les warnings HTML5 (libxml n'aime pas <datalist>, etc.)
    libxml_use_internal_errors(true);
    $loaded = @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    if (!$loaded) {
        echo "  ❌ Bug03 — DOMDocument n'a pas pu parser le HTML rendu\n";
        return false;
    }

    // XPath : chercher tous les <form> qui sont descendants d'un autre <form>.
    $xpath = new \DOMXPath($dom);
    $nested_forms = $xpath->query('//form//form');
    if ($nested_forms === false) {
        echo "  ❌ Bug03 — XPath //form//form a échoué\n";
        return false;
    }
    $nested_count = $nested_forms->length;

    if ($nested_count > 0) {
        echo "  ❌ Bug03 — $nested_count <form> imbriqué(s) détecté(s) dans admin_settings.php — bug réapparu\n";
        // Afficher le contexte du premier form imbriqué pour le debug
        $first_nested = $nested_forms->item(0);
        if ($first_nested) {
            $outer = $first_nested->parentNode;
            while ($outer && $outer->nodeName !== 'form') {
                $outer = $outer->parentNode;
            }
            if ($outer) {
                echo "     Form externe : " . substr($dom->saveHTML($outer), 0, 400) . "...\n";
            }
            echo "     Form imbriqué : " . substr($dom->saveHTML($first_nested), 0, 400) . "...\n";
        }
        return false;
    }

    // Bonus : compter le nombre total de <form> pour s'assurer qu'on a bien
    // testé quelque chose (au moins 3 forms : sécurité email, save_settings,
    // webhook Enregistrer, test_webhook optionnel, test_email).
    $all_forms = $xpath->query('//form');
    $total_forms = $all_forms !== false ? $all_forms->length : 0;
    if ($total_forms < 3) {
        echo "  ⚠ Bug03 — Seulement $total_forms <form> trouvé(s) dans admin_settings.php (attendu : ≥ 3). Test partiel.";
        echo "     → Vérifier que le rendu est bien complet.\n";
    }

    echo "  ✅ Bug03 — Aucun <form> imbriqué dans admin_settings.php ($total_forms forms siblings — structure HTML valide)\n";
    return true;
}
