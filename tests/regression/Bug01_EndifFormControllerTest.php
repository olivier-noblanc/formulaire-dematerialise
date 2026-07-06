<?php
declare(strict_types=1);
/**
 * Bug 01 — FormController endif mal placé (P0)
 *
 * Symptôme historique : après une soumission réussie, l'encadré RGPD + le
 * bouton « Envoyer ma demande » réapparaissaient SOUS le message
 * « Demande enregistrée ».
 *
 * Cause : `<?php endif; ?>` fermait `if ($success)` au lieu du `foreach`
 *         dans src/Controller/FormController.php::renderContent().
 *
 * Test minimal : invoquer FormController::handle() avec POST + CSRF + tous
 * les champs requis + rgpd_consent=1 → le HTML de la page succès NE doit
 * PAS contenir `name="rgpd_consent"` NI « Envoyer ma demande ».
 *
 * Fichier : tests/regression/Bug01_EndifFormControllerTest.php
 *
 * Stratégie :
 *  - On désactive TEST_MODE pour récupérer le HTML rendu (en TEST_MODE,
 *    FormController appelle test_json_response() avant le rendu).
 *  - On invoque FormController::handle() en sous-processus PHP (pattern
 *    hérité de tests/test_form_render_html.php).
 *  - On fournit un CSRF token valide via $_SESSION (bypass maison) + tous
 *    les champs requis du formulaire « onboarding ».
 *  - On vérifie que la page succès contient « Demande enregistrée » et
 *    NE contient ni `name="rgpd_consent"` ni « Envoyer ma demande ».
 *  - On nettoie la soumission créée en base (et ses tokens) pour ne pas
 *    polluer workflow.db entre les exécutions répétées du test.
 *
 * @package tests\regression
 */

require_once __DIR__ . '/_subprocess_helper.php';

/**
 * Lance le test de non-régression Bug 01.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug01_test(): bool {
    // Email unique pour ce test (pour faciliter le nettoyage).
    // DREETS\test-bug01 → test-bug01@dreets.gouv.fr (via AuthService::getUser()).
    $test_user_server = 'DREETS\test-bug01';

    // 1. CSRF token fixé à une valeur connue. On le passe au sous-processus
    //    via une variable $_SERVER dédiée que le script lira.
    $csrf_token = 'bug01_csrf_' . bin2hex(random_bytes(8));

    // 2. Données POST : tous les champs requis du formulaire onboarding.
    $post_fields = http_build_query([
        'csrf_token'          => $csrf_token,
        'nom'                 => 'Bug01',
        'prenom'              => 'TesteurRegression',
        'date_naissance'      => '1990-01-01',
        'date_prise_poste'    => '2026-12-01',
        'corps_grade'         => "Attaché d'administration",
        'type_arrivee'        => 'Mutation',
        'affectation'         => 'Service Test Bug01',
        'quotite'             => '100%',
        'type_poste'          => 'Fixe',
        'log_batiment_bureau' => 'Bat N 300',
        'rgpd_consent'        => '1',
    ]);

    // 3. Corps du sous-processus : NOWDOC (pas d'interpolation) — les
    //    variables PHP ($project_root, $_SESSION, $_POST, get_pdo(), etc.)
    //    sont préservées telles quelles pour être exécutées dans le
    //    sous-processus.
    $script_body = <<<'PHP'
require_once $project_root . "/helpers.php";

// Démarrer session et pré-charger le CSRF token (bypass maison pour le test).
// Le token est transmis via $_SERVER["HTTP_X_BUG01_CSRF"] par le helper.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION["csrf_token"] = $_SERVER["HTTP_X_BUG01_CSRF"] ?? "";

// $_POST est déjà peuplé par le helper via stdin (parse_str).
// On appelle FormController::handle() en capturant le HTML rendu.
ob_start();
try {
    $controller = new App\Controller\FormController();
    $controller->handle();
    $html = ob_get_clean();
} catch (\Throwable $e) {
    $html = ob_get_clean() . "\n__EXCEPTION__:" . $e->getMessage() . "\n" . $e->getTraceAsString();
}

// ── Nettoyage DB : supprimer la soumission et ses tokens créés par le test ──
try {
    $pdo = get_pdo();
    $email = "test-bug01@dreets.gouv.fr";
    $stmt = $pdo->prepare("SELECT id FROM submissions WHERE submitted_by = ?");
    $stmt->execute([$email]);
    $sub_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($sub_ids)) {
        $in = implode(",", array_fill(0, count($sub_ids), "?"));
        $pdo->prepare("DELETE FROM tokens WHERE submission_id IN ($in)")->execute($sub_ids);
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id IN ($in)")->execute($sub_ids);
    }
    $pdo->prepare("DELETE FROM submissions WHERE submitted_by = ?")->execute([$email]);
} catch (\Throwable $e) {
    // Le nettoyage ne doit pas masquer le résultat du test — on loggue juste
    error_log("Bug01 cleanup error: " . $e->getMessage());
}

echo $html;
PHP;

    $r = run_regression_script(
        $script_body,
        [
            'AUTH_USER'           => $test_user_server,
            'REQUEST_METHOD'      => 'POST',
            'QUERY_STRING'        => 'f=onboarding',
            'REQUEST_URI'         => '/form.php?f=onboarding',
            'CONTENT_TYPE'        => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH'      => (string) strlen($post_fields),
            'HTTP_X_BUG01_CSRF'   => $csrf_token,
        ],
        $post_fields
    );

    // 4. Assertions sur le HTML rendu
    $html = $r['stdout'];

    // Vérifier qu'il n'y a pas d'exception dans le HTML
    if (strpos($html, '__EXCEPTION__') !== false) {
        echo "  ❌ Bug01 — Une exception a été levée pendant l'exécution\n";
        echo "     " . substr($html, strpos($html, '__EXCEPTION__'), 1000) . "\n";
        if (!empty($r['stderr'])) {
            echo "     STDERR : " . substr($r['stderr'], 0, 600) . "\n";
        }
        return false;
    }

    // Vérifier qu'on a bien un succès (sinon le test est inopérant)
    if (strpos($html, 'Demande enregistrée') === false) {
        echo "  ❌ Bug01 — Le POST n'a pas abouti à un succès (« Demande enregistrée » absent). Le test ne peut pas valider le bug.\n";
        echo "     HTML (extrait) : " . substr($html, 0, 800) . "\n";
        if (!empty($r['stderr'])) {
            echo "     STDERR : " . substr($r['stderr'], 0, 600) . "\n";
        }
        return false;
    }

    // Assertion 1 : pas de checkbox rgpd_consent dans la page succès
    if (strpos($html, 'name="rgpd_consent"') !== false) {
        echo "  ❌ Bug01 — La checkbox rgpd_consent fuite sur la page succès (bug endif réapparu)\n";
        echo "     HTML (extrait) : " . substr($html, 0, 1500) . "\n";
        return false;
    }

    // Assertion 2 : pas de bouton « Envoyer ma demande » sur la page succès
    if (strpos($html, 'Envoyer ma demande') !== false) {
        echo "  ❌ Bug01 — Le bouton « Envoyer ma demande » fuite sur la page succès (bug endif réapparu)\n";
        echo "     HTML (extrait) : " . substr($html, 0, 1500) . "\n";
        return false;
    }

    echo "  ✅ Bug01 — Page succès sans fuite RGPD ni bouton submit (endif correct)\n";
    return true;
}
