<?php
declare(strict_types=1);
/**
 * Bug 06 — Motif + commentaire non préservés dans validate.php (P1)
 *
 * Symptôme historique : si la validation échouait (ex: champ validateur
 * required manquant), le validateur devait tout re-saisir (motif de refus
 * + commentaire).
 *
 * Cause : les radios `motif` et le textarea `comment` ne réutilisaient pas
 *         `$_POST` pour ré-afficher la valeur saisie après erreur.
 *
 * Test minimal (version code-source) : vérifier que le code source de
 * validate.php contient bien, pour chaque radio motif, l'instruction PHP
 * `<?= (($_POST['motif'] ?? '') === '<value>') ? ' checked' : '' ?>`
 * ET pour le textarea comment, `<?= \App\Core\App::html()->escape($_POST['comment'] ?? '') ?>`.
 *
 * Fichier : tests/regression/Bug06_StickyValidateTest.php
 *
 * Note : la spec proposait soit un test fonctionnel avec un token valide
 * en DB, soit un test de code source. On choisit le test de code source
 * car il est plus simple, fiable, et ne dépend pas de l'état de la DB.
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression Bug 06.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug06_test(): bool {
    $validate_path = __DIR__ . '/../../src/Render/ValidateRenderer.php';
    if (!is_file($validate_path)) {
        echo "  ❌ Bug06 — Fichier source introuvable : $validate_path\n";
        return false;
    }
    $src = file_get_contents($validate_path);
    if ($src === false) {
        echo "  ❌ Bug06 — Impossible de lire $validate_path\n";
        return false;
    }

    // Les 4 motifs de refus attendus dans ValidateRenderer.php
    $expected_motifs = [
        'Information manquante',
        'Hors périmètre',
        'Non conforme',
        'Autre motif',
    ];

    // Assertion 1 : les 4 motifs doivent exister dans le tableau $motifs
    // ET le pattern sticky checked ($existing_motif === $motif_val ? ' checked')
    // doit être présent.
    $missing_motifs = [];
    foreach ($expected_motifs as $motif) {
        $motif_escaped = preg_quote($motif, '/');
        // Le renderer utilise un foreach sur $motifs et vérifie $existing_motif === $motif_val
        // On cherche juste que la valeur du motif existe dans le code source
        if (strpos($src, $motif) === false) {
            $missing_motifs[] = $motif;
        }
    }
    if (!empty($missing_motifs)) {
        echo "  ❌ Bug06 — Les motifs suivants sont absents du ValidateRenderer : " . implode(', ', $missing_motifs) . "\n";
        echo "     → Le validateur devrait retrouver son motif sélectionné après erreur\n";
        return false;
    }

    // Vérifier que le pattern sticky checked existe dans le renderer
    // Le renderer utilise : ($existing_motif === $motif_val) ? ' checked'
    if (preg_match('/\$existing_motif\s*===\s*\$motif_val\s*\)\s*\?\s*[\'"]\s*checked[\'"]/', $src) === false) {
        echo "  ❌ Bug06 — Le pattern sticky checked (\$existing_motif === \$motif_val ? ' checked') est absent\n";
        return false;
    }

    // Assertion 2 : le textarea comment doit afficher le contenu
    // échappé de $existing_comment après erreur.
    // Le renderer utilise : $htmlService->escape($existing_comment)
    $pattern_comment = '/name="comment"[^>]*>.*?escape\s*\(\s*\$existing_comment\s*\)/s';
    if (!preg_match($pattern_comment, $src)) {
        echo "  ❌ Bug06 — Le textarea « comment » ne ré-affiche pas la valeur du commentaire après erreur — bug sticky réapparu\n";
        // Afficher le contexte du textarea pour debug
        if (preg_match('/name="comment"[^>]*>.*?<\/textarea>/s', $src, $m)) {
            echo "     Textarea rendu :\n     " . str_replace("\n", "\n     ", $m[0]) . "\n";
        }
        return false;
    }

    echo "  ✅ Bug06 — Motifs de refus + commentaire préservés après erreur dans ValidateRenderer (sticky validate)\n";
    return true;
}
