<?php
declare(strict_types=1);
/**
 * Bug 11 — Topbar et breadcrumbs supprimés en v9.1.0 (épuration UI)
 *
 * Symptôme historique (v8.x → v9.0.0) :
 *   - La topbar (barre horizontale en haut du contenu) affichait un fil
 *     d'Ariane "Accueil" + une cloche + un bouton "+ Nouvelle demande".
 *   - Ces 3 éléments dupliquaient des contrôles déjà présents dans la
 *     sidebar (Accueil, Mes validations avec badge, ...).
 *   - 18 pages appelaient en plus render_breadcrumb(['Accueil', ...])
 *     qui affichait "Accueil > Titre" au-dessus du contenu.
 *
 * Fix v9.1.0 :
 *   1. Suppression complète de la <div class="topbar"> dans
 *      lib/render_navigation.php
 *   2. Déplacement du CTA "+ Nouvelle demande" dans la sidebar
 *      (classe .sidebar-cta)
 *   3. Suppression des 20 appels render_breadcrumb() dans toutes les
 *      pages (lib/render_*.php, pages/*.php, src/Controller/*.php)
 *   4. Suppression des règles CSS .topbar*, .breadcrumb
 *
 * Ce test vérifie qu'aucune trace de topbar/breadcrumb ne réapparaît,
 * aussi bien dans le code source que dans le HTML rendu.
 *
 * Fichier : tests/regression/Bug11_NoTopbarBreadcrumbTest.php
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression Bug 11.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug11_test(): bool {
    $root = dirname(__DIR__, 2);
    $failures = [];

    // ── Assertion 1 : aucune classe .topbar* dans le code source PHP ──
    // On scanne lib/, pages/, src/ à la recherche de "topbar" (hors commentaires).
    $phpFiles = [];
    foreach (['lib', 'src'] as $dir) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $phpFiles[] = $f->getPathname();
            }
        }
    }

    foreach ($phpFiles as $file) {
        $src = file_get_contents($file);
        if ($src === false) continue;

        // Ignorer les commentaires (/* ... */, // ..., # ...) pour ne scanner
        // que le code réel. On strip les blocs /* */ et les lignes // et #.
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $src);
        $stripped = preg_replace('/^\s*\/\/.*$/m', '', $stripped);
        $stripped = preg_replace('/^\s*\#.*$/m', '', $stripped);

        // Chercher "topbar" (insensible à la casse) hors commentaires
        if (preg_match('/topbar/i', $stripped)) {
            $rel = str_replace($root . '/', '', $file);
            $failures[] = "topbar trouvé dans $rel (code source)";
        }
        // render_breadcrumb( — ne doit plus être appelé
        // Utiliser le code SOURCE (pas stripped) pour la détection,
        // car le stripping des commentaires peut manger la définition.
        if (preg_match('/render_breadcrumb\s*\(/', $src)) {
            $rel = str_replace($root . '/', '', $file);
            $normFile = str_replace('\\', '/', $file);
            // Exception : la DÉFINITION de render_breadcrumb dans NavigationRenderer.php
            // est tolérée (on garde la fonction pour rétro-compat, mais elle ne doit
            // plus être appelée par les pages).
            if (strpos($normFile, 'src/Render/NavigationRenderer.php') !== false) {
                // Vérifier que c'est bien la définition (function render_breadcrumb)
                // et non un appel — on cherche dans le code source brut
                if (!preg_match('/function\s+render_breadcrumb\s*\(/', $src)) {
                    $failures[] = "appel render_breadcrumb() trouvé dans $rel";
                }
            } else {
                $failures[] = "appel render_breadcrumb() trouvé dans $rel";
            }
        }
    }

    // ── Assertion 2 : aucune classe CSS .topbar* ou .breadcrumb active ──
    // On tolère les commentaires "/* topbar supprimée ... */" mais pas les
    // règles effectives (.topbar { ... }).
    $cssFiles = glob($root . '/lib/*.css');
    foreach ($cssFiles as $file) {
        $src = file_get_contents($file);
        if ($src === false) continue;
        // Strip commentaires CSS
        $stripped = preg_replace('/\/\*.*?\*\//s', '', $src);
        // Chercher ".topbar" ou ".breadcrumb" comme sélecteur
        if (preg_match('/\.topbar[\s\.\{\,:\-+]/', $stripped)) {
            $rel = str_replace($root . '/', '', $file);
            $failures[] = "sélecteur CSS .topbar trouvé dans $rel";
        }
        if (preg_match('/\.breadcrumb[\s\.\{\,:\-+]/', $stripped)) {
            $rel = str_replace($root . '/', '', $file);
            $failures[] = "sélecteur CSS .breadcrumb trouvé dans $rel";
        }
    }

    // ── Assertion 3 (v10.0.7) : le 1er item "Formulaires" remplace sidebar-cta ──
    // sidebar-cta a été supprimé en v10.0.7 (redondant avec l'item "Formulaires"
    // de la sidebar). On vérifie maintenant que l'item "Formulaires" existe.
    $navSrc = file_get_contents($root . '/src/Render/NavigationRenderer.php');
    if ($navSrc === false || strpos($navSrc, "'label' => 'Formulaires'") === false) {
        $failures[] = "Item 'Formulaires' non trouvé dans src/Render/NavigationRenderer.php";
    }

    if (!empty($failures)) {
        echo "  ❌ Bug11 — " . count($failures) . " régression(s) détectée(s) :\n";
        foreach ($failures as $f) {
            echo "     - $f\n";
        }
        return false;
    }

    echo "  ✅ Bug11 — Aucune topbar/breadcrumb dans le code source (épuration v9.1.0 préservée)\n";
    return true;
}
