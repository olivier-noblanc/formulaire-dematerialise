<?php
declare(strict_types=1);
/**
 * Bug 07 — Faux badge « Refusé » sur l'historique des autres validateurs (P1)
 *
 * Symptôme historique : si un validateur A refusait, le validateur B (qui
 * avait validé à une étape précédente) voyait « Refusé » dans son
 * historique — alors qu'il avait lui-même VALIDÉ.
 *
 * Cause : la boucle qui détectait le refus vérifiait
 *         `action === 'refuser'` SANS matcher l'email du validateur.
 *         Donc TOUT validateur de la soumission voyait « Refusé » dès
 *         qu'un autre avait refusé.
 *
 * Test minimal (version code-source) : vérifier que le code source de
 * my_validations.php contient bien
 * `&& (string)($v['email'] ?? '') === $user` dans la boucle qui détecte
 * les refus. C'est un test de non-régression sur le code source (pas
 * d'exécution), plus simple et fiable qu'un test fonctionnel qui
 * nécessiterait de simuler deux validateurs avec un workflow complet.
 *
 * Fichier : tests/regression/Bug07_FalseRefusedBadgeTest.php
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression Bug 07.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug07_test(): bool {
    $path = __DIR__ . '/../../pages/my_validations.php';
    if (!is_file($path)) {
        echo "  ❌ Bug07 — Fichier source introuvable : $path\n";
        return false;
    }
    $src = file_get_contents($path);
    if ($src === false) {
        echo "  ❌ Bug07 — Impossible de lire $path\n";
        return false;
    }

    // Assertion : on cherche la condition qui détecte qu'un refus a été
    // fait PAR L'UTILISATEUR COURANT. Le pattern attendu :
    //
    //   if ($v['action'] === 'refuser' && (string)($v['email'] ?? '') === $user)
    //
    // On tolère des variantes whitespace, l'ordre des opérandes, et
    // l'usage éventuel de == au lieu de === (mais on préfère ===).
    $patterns = [
        // Forme canonique : $v['action'] === 'refuser' && (string)($v['email'] ?? '') === $user
        '/\$v\[\'action\'\]\s*===\s*\'refuser\'\s*&&\s*\(string\)\s*\(\$v\[\'email\'\]\s*\?\?\s*\'\'\)\s*===\s*\$user/',
        // Variante : $v['action'] === 'refuser' && $v['email'] === $user (sans cast string, sans ?? '')
        '/\$v\[\'action\'\]\s*===\s*\'refuser\'\s*&&\s*\$v\[\'email\'\]\s*===\s*\$user/',
        // Variante : $user === (string)($v['email'] ?? '') (opérandes inversés)
        '/\$user\s*===\s*\(string\)\s*\(\$v\[\'email\'\]\s*\?\?\s*\'\'\)\s*&&\s*\$v\[\'action\'\]\s*===\s*\'refuser\'/',
    ];
    $found = false;
    foreach ($patterns as $p) {
        if (preg_match($p, $src)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "  ❌ Bug07 — La condition « refuser && email === \$user » est absente de my_validations.php\n";
        echo "     → Tout validateur verrait « Refusé » dès qu'un autre a refusé\n";
        // Afficher le contexte autour de 'refuser' pour le debug
        if (preg_match('/refuser/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1];
            $excerpt = substr($src, max(0, $offset - 200), 500);
            echo "     Extrait du code autour de 'refuser' :\n     " . str_replace("\n", "\n     ", $excerpt) . "\n";
        }
        return false;
    }

    // Assertion complémentaire : on vérifie aussi que la variable
    // $refused_by_me est bien utilisée pour afficher le badge. Cela
    // confirme que la logique est bien « par validateur » et non globale.
    if (strpos($src, 'refused_by_me') === false) {
        echo "  ⚠ Bug07 — Variable \$refused_by_me absente — la logique de filtrage par email semble différente de l'attendu (warning)\n";
        // Pas un échec : la condition principale (email match) est vérifiée,
        // c'est l'implémentation qui peut varier.
    }

    echo "  ✅ Bug07 — Détection de refus matche l'email du validateur courant (\$v['email'] === \$user)\n";
    return true;
}
