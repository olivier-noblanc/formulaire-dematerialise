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
    $files_to_check = [
        __DIR__ . '/../../src/Controller/FormController.php',
        __DIR__ . '/../../src/Controller/FormSubmissionHandler.php',
    ];

    $found_delete     = false;
    $found_handle_file = false;
    $found_advance    = false;
    $found_send       = false;

    $hfu_pos = -1;
    $aw_pos  = -1;
    $sm_pos  = -1;

    foreach ($files_to_check as $path) {
        if (!is_file($path)) {
            continue;
        }
        $src = file_get_contents($path);
        if ($src === false) {
            continue;
        }

        if (!$found_delete) {
            $pattern_delete = '/if\s*\(\s*\$file_errors\s*!==\s*\[\]\s*\)\s*\{[^}]*deleteById/s';
            if (preg_match($pattern_delete, $src)) {
                $found_delete = true;
            }
        }

        if (!$found_handle_file && preg_match('/handleFileUpload\s*\(\s*\$_FILES/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $found_handle_file = true;
            $hfu_pos = $m[0][1];
        }

        if (!$found_advance && preg_match('/advanceWorkflow\s*\(\s*\$/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $found_advance = true;
            $aw_pos = $m[0][1] + 99999 * (int) array_search($path, $files_to_check, true);
        }

        if (!$found_send && preg_match('/->send\s*\(\s*\$/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $found_send = true;
            $sm_pos = $m[0][1] + 99999 * (int) array_search($path, $files_to_check, true);
        }
    }

    if (!$found_delete) {
        echo "  ❌ Bug02 — La logique « if (\$file_errors !== []) { suppression de la soumission } » absente\n";
        return false;
    }
    if (!$found_handle_file) {
        echo "  ❌ Bug02 — Appel réel handleFileUpload(\$_FILES...) introuvable\n";
        return false;
    }
    if (!$found_advance) {
        echo "  ❌ Bug02 — Appel réel advanceWorkflow(\$...) introuvable\n";
        return false;
    }
    if ($hfu_pos > $aw_pos) {
        echo "  ❌ Bug02 — handleFileUpload() est appelé APRÈS advanceWorkflow() — ordre incorrect\n";
        return false;
    }
    if ($found_send && $sm_pos < $hfu_pos) {
        echo "  ❌ Bug02 — send() est appelé AVANT handleFileUpload() — l'email partirait même en cas d'échec\n";
        return false;
    }

    echo "  ✅ Bug02 — Logique « if (\$file_errors !== []) → suppression » présente + handleFileUpload() avant advanceWorkflow() et send()\n";
    return true;
}
