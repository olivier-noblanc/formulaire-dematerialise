<?php
declare(strict_types=1);
/**
 * Bug 05 — Checkbox RGPD non préservée après erreur (P1)
 *
 * Symptôme historique : si l'utilisateur oubliait un champ obligatoire,
 * le formulaire ré-affichait la checkbox RGPD DÉCOCHÉE — l'utilisateur
 * devait donc re-cocher la case à chaque tentative.
 *
 * Cause : l'attribut `checked` n'était pas ajouté à la checkbox RGPD en
 *         fonction de `$_POST['rgpd_consent']` dans le template.
 *
 * Test minimal : POST /form.php?f=onboarding avec rgpd_consent=1 + un
 * champ obligatoire vide → le HTML ré-affiché doit contenir
 * `<input type="checkbox" name="rgpd_consent" ... checked`.
 *
 * Fichier : tests/regression/Bug05_StickyRgpdTest.php
 *
 * @package tests\regression
 */

require_once __DIR__ . '/_subprocess_helper.php';

/**
 * Lance le test de non-régression Bug 05.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug05_test(): bool {
    // CSRF token fixé. On n'a PAS besoin qu'il soit valide : le test vérifie
    // que la validation CHAMP OBLIGATOIRE MANQUANT court-circuite AVANT le
    // contrôle CSRF... mais en réalité, le CSRF est vérifié en PREMIER.
    // Donc si CSRF échoue, le formulaire est ré-affiché en erreur CSRF, et
    // la checkbox RGPD n'est PAS cochée (le $_POST['rgpd_consent'] est bien
    // là mais le code regarde `!empty($_POST['rgpd_consent'])` qui serait
    // vrai).
    //
    // En réalité, FormController::handle() appelle requireCsrf() en TOUT
    // PREMIER dans le POST. Si CSRF échoue → render_error_page(403) qui
    // affiche une page d'erreur, PAS le formulaire. Donc la checkbox RGPD
    // ne serait JAMAIS ré-affichée.
    //
    // → On doit bypasser le CSRF en injectant $_SESSION['csrf_token']
    //   avant l'appel (cf. test_form_render_html.php pour la même technique).
    $csrf_token = 'bug05_csrf_' . bin2hex(random_bytes(8));

    // POST avec rgpd_consent=1 MAIS avec un champ obligatoire manquant
    // (affectation vide) → la validation champ obligatoire doit échouer,
    // le formulaire est ré-affiché, et la checkbox RGPD doit être checked.
    $post_fields = http_build_query([
        'csrf_token'          => $csrf_token,
        'nom'                 => 'Bug05',
        'prenom'              => 'TesteurRegression',
        'date_naissance'      => '1990-01-01',
        'date_prise_poste'    => '2026-12-01',
        'corps_grade'         => "Attaché d'administration",
        'type_arrivee'        => 'Mutation',
        'affectation'         => '',           // ← champ obligatoire manquant
        'quotite'             => '100%',
        'type_poste'          => 'Fixe',
        'log_batiment_bureau' => 'Bat N 300',
        'rgpd_consent'        => '1',          // ← checkbox cochée
    ]);

    $script_body = <<<'PHP'
require_once $project_root . "/helpers.php";

// Démarrer session et pré-charger le CSRF token (bypass maison pour le test)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION["csrf_token"] = $_SERVER["HTTP_X_BUG05_CSRF"] ?? "";

ob_start();
try {
    $controller = new App\Controller\FormController();
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
            'AUTH_USER'         => 'DREETS\test-bug05',
            'REQUEST_METHOD'    => 'POST',
            'QUERY_STRING'      => 'f=onboarding',
            'REQUEST_URI'       => '/form.php?f=onboarding',
            'CONTENT_TYPE'      => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH'    => (string) strlen($post_fields),
            'HTTP_X_BUG05_CSRF' => $csrf_token,
        ],
        $post_fields
    );

    $html = $r['stdout'];

    // Vérifier qu'il n'y a pas d'exception
    if (strpos($html, '__EXCEPTION__') !== false) {
        echo "  ❌ Bug05 — Une exception a été levée pendant l'exécution\n";
        echo "     " . substr($html, strpos($html, '__EXCEPTION__'), 1500) . "\n";
        if (!empty($r['stderr'])) {
            echo "     STDERR : " . substr($r['stderr'], 0, 600) . "\n";
        }
        return false;
    }

    // Vérifier qu'on est bien en mode « ré-affichage avec erreur » (pas en succès)
    if (strpos($html, 'Demande enregistrée') !== false) {
        echo "  ❌ Bug05 — Le POST a réussi alors qu'on attendait une erreur de validation (affectation vide)\n";
        return false;
    }

    // Vérifier qu'on a bien le message d'erreur de validation
    if (strpos($html, 'Ce champ est obligatoire') === false) {
        echo "  ⚠ Bug05 — Pas de message « Ce champ est obligatoire » dans le HTML — la validation n'a peut-être pas détecté le champ manquant\n";
        echo "     HTML (extrait) : " . substr($html, 0, 1000) . "\n";
    }

    // Assertion principale : la checkbox rgpd_consent doit être `checked`
    // On cherche le pattern `<input type="checkbox" name="rgpd_consent" ... checked`
    // (l'attribut checked peut apparaître à n'importe quelle position dans le tag)
    if (!preg_match('/<input[^>]*\bname="rgpd_consent"[^>]*\bchecked\b/', $html)) {
        echo "  ❌ Bug05 — La checkbox rgpd_consent n'est PAS cochée après ré-affichage — bug réapparu\n";
        // Extraire le contexte pour debug
        if (preg_match('/<input[^>]*name="rgpd_consent"[^>]*>/i', $html, $m)) {
            echo "     Checkbox rendue : " . $m[0] . "\n";
        } else {
            echo "     Aucune checkbox rgpd_consent trouvée dans le HTML ! HTML (extrait) : " . substr($html, 0, 1500) . "\n";
        }
        return false;
    }

    echo "  ✅ Bug05 — Checkbox RGPD préservée (checked) après erreur de validation (sticky RGPD)\n";
    return true;
}
