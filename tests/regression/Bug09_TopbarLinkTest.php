<?php
declare(strict_types=1);
/**
 * Bug 09 — Topbar « Nouvelle demande » pointait vers onboarding (P2)
 *
 * Symptôme historique : le bouton « + Nouvelle demande » de la topbar
 * ouvrait TOUJOURS `form.php?f=onboarding` au lieu du sélecteur de
 * formulaires (page d'accueil avec ancre `#form-cards`).
 *
 * Cause : dans lib/render_navigation.php, le lien topbar-cta pointait
 * vers `form.php?f=onboarding` au lieu de `index.php#form-cards`.
 *
 * Évolution v9.1.0 : topbar supprimée, CTA déplacé en sidebar (sidebar-cta).
 * Évolution v10.0.7 : sidebar-cta supprimé (redondant avec le 1er item
 *   "Formulaires" de la sidebar). Le test vérifie maintenant que :
 *   1. Le 1er item de navigation "Formulaires" pointe vers index.php
 *   2. Aucun lien ne pointe vers form.php?f=onboarding (le bug historique)
 *   3. Aucun topbar ou sidebar-cta résiduel
 *
 * Fichier : tests/regression/Bug09_TopbarLinkTest.php
 */

/**
 * Lance le test de non-régression Bug 09.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug09_test(): bool {
    $path = __DIR__ . '/../../src/Render/NavigationRenderer.php';
    if (!is_file($path)) {
        echo "  ❌ Bug09 — Fichier source introuvable : $path\n";
        return false;
    }
    $src = file_get_contents($path);
    if ($src === false) {
        echo "  ❌ Bug09 — Impossible de lire $path\n";
        return false;
    }

    // Assertion 1 : le 1er item de navigation "Formulaires" pointe vers index.php
    // v10.0.7 — remplacé sidebar-cta par item "Formulaires" dans $main_links
    $has_formulaires_link = strpos($src, "'label' => 'Formulaires'") !== false
                          && strpos($src, "'href' => 'index.php'") !== false;
    if (!$has_formulaires_link) {
        echo "  ❌ Bug09 — Lien 'Formulaires' → index.php non trouvé dans NavigationRenderer.php\n";
        return false;
    }

    // Assertion 2 : aucun lien vers form.php?f=onboarding (le bug historique)
    if (strpos($src, 'form.php?f=onboarding') !== false) {
        echo "  ❌ Bug09 — Lien vers form.php?f=onboarding toujours présent (bug historique)\n";
        return false;
    }

    // Assertion 3 (v10.0.7) : plus de topbar ni sidebar-cta résiduel
    if (preg_match('/class=["\']topbar["\']/', $src)) {
        echo "  ❌ Bug09 — La topbar a réapparu dans NavigationRenderer.php\n";
        return false;
    }
    // sidebar-cta supprimé en v10.0.7 — vérifier qu'il n'est pas revenu
    // (sauf dans les commentaires)
    $stripped = preg_replace('/\/\*.*?\*\//s', '', $src);
    $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped);
    $stripped = preg_replace('/^\s*\#.*$/m', '', $stripped);
    if (preg_match('/sidebar-cta/', $stripped)) {
        echo "  ❌ Bug09 — sidebar-cta a réapparu dans NavigationRenderer.php (supprimé en v10.0.7)\n";
        return false;
    }

    echo "  ✅ Bug09 — Item 'Formulaires' → index.php (sélecteur de formulaires), pas de topbar/sidebar-cta résiduel\n";
    return true;
}
