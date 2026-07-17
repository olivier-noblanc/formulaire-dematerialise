<?php
declare(strict_types=1);
/**
 * Bug 02 — Upload fichier échec silencieux (P0)
 *
 * Symptôme historique : si un upload échouait, la soumission était marquée
 * « succès », l'email partait, les validateurs voyaient une soumission sans
 * pièce jointe.
 *
 * Cause : `handle_file_upload()` était appelé APRÈS `advance_workflow()` +
 *         `send_mail()`. La soumission était donc déjà créée, le workflow
 *         déjà déclenché, et l'email déjà envoyé au moment où l'échec
 *         d'upload était détecté.
 *
 * Test minimal (version code-source) : vérifier que le code source de
 * src/Controller/FormController.php contient bien la logique
 * « if (!empty($file_errors)) { DELETE FROM submissions ... } » qui
 * annule la soumission en cas d'échec d'upload. On vérifie AUSSI que
 * `handle_file_upload()` est appelé AVANT `advance_workflow()`.
 *
 * Fichier : tests/regression/Bug02_UploadFailureTest.php
 *
 * Note : La spec proposait deux approches — soit simuler un upload qui
 * échoue via $_FILES dans un sous-processus (complexe car aucun
 * formulaire existant n'a de champ file), soit inspecter le code source.
 * On choisit l'inspection du code source, plus simple et plus fiable,
 * qui détecte précisément le pattern « DELETE FROM submissions » dans la
 * branche d'erreur d'upload.
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression Bug 02.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug02_test(): bool {
    $controller_path = __DIR__ . '/../../src/Controller/FormController.php';
    if (!is_file($controller_path)) {
        echo "  ❌ Bug02 — Fichier source introuvable : $controller_path\n";
        return false;
    }
    $src = file_get_contents($controller_path);
    if ($src === false) {
        echo "  ❌ Bug02 — Impossible de lire $controller_path\n";
        return false;
    }

    // Assertion 1 : présence de la logique « if ($file_errors !== []) »
    // suivie d'une suppression de la soumission (deleteById ou DELETE FROM).
    // On autorise le formatage whitespace variable via une regex.
    $pattern_delete = '/if\s*\(\$file_errors\s*!==\s*\[\]\s*\)\s*\{[^}]*deleteById|if\s*\(\s*!\s*empty\s*\(\s*\$file_errors\s*\)\s*\)\s*\{[^}]*DELETE\s+FROM\s+submissions/s';
    if (!preg_match($pattern_delete, $src)) {
        echo "  ❌ Bug02 — La logique « if (\$file_errors !== []) { suppression de la soumission } » est absente du FormController\n";
        echo "     → Le bug historique d'upload silencieux pourrait réapparaître\n";
        // Afficher un extrait autour de file_errors pour le debug
        if (preg_match('/\$file_errors/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $excerpt = substr($src, max(0, $offset - 100), 400);
            echo "     Extrait du code :\n     " . str_replace("\n", "\n     ", $excerpt) . "\n";
        }
        return false;
    }

    // Assertion 2 : `handleFileUpload()` doit être appelé AVANT
    // `advanceWorkflow()`. C'est l'ordre correct : si l'upload échoue,
    // on nettoie avant de déclencher le workflow.
    // On cherche l'appel RÉEL (avec une variable en argument, pas une
    // mention dans un commentaire).
    if (!preg_match('/handleFileUpload\s*\(\s*\$_FILES/', $src, $m1, PREG_OFFSET_CAPTURE)) {
        echo "  ❌ Bug02 — Appel réel handleFileUpload(\$_FILES...) introuvable dans le FormController\n";
        return false;
    }
    if (!preg_match('/advanceWorkflow\s*\(\s*\$/', $src, $m2, PREG_OFFSET_CAPTURE)) {
        echo "  ❌ Bug02 — Appel réel advanceWorkflow(\$...) introuvable dans le FormController\n";
        return false;
    }
    $hfu_pos = $m1[0][1];
    $aw_pos  = $m2[0][1];
    if ($hfu_pos > $aw_pos) {
        echo "  ❌ Bug02 — handleFileUpload() est appelé APRÈS advanceWorkflow() — ordre incorrect\n";
        echo "     → handleFileUpload() à l'offset $hfu_pos, advanceWorkflow() à l'offset $aw_pos\n";
        return false;
    }

    // Assertion 3 : `App::mail()->send()` doit AUSSI être appelé APRÈS
    // `handleFileUpload()`. Sinon, l'email partirait même si l'upload échoue.
    if (!preg_match('/App::mail\(\)->send\s*\(\s*\$/', $src, $m3, PREG_OFFSET_CAPTURE)) {
        echo "  ⚠ Bug02 — Appel réel App::mail()->send(\$...) introuvable dans le FormController (warning, pas un échec)\n";
    } else {
        $sm_pos = $m3[0][1];
        if ($sm_pos < $hfu_pos) {
            echo "  ❌ Bug02 — App::mail()->send() est appelé AVANT handleFileUpload() — l'email partirait même en cas d'échec d'upload\n";
            return false;
        }
    }

    echo "  ✅ Bug02 — Logique « if (\$file_errors !== []) → suppression » présente + handleFileUpload() avant advanceWorkflow() et send()\n";
    return true;
}
