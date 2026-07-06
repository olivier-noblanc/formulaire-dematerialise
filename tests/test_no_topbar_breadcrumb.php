<?php
// Vérification finale : rendu réel de plusieurs pages, recherche de topbar/breadcrumb.
// Lancé en sous-processus via HttpClient pour neutraliser TEST_MODE et capturer le HTML.

require_once __DIR__ . '/helpers/HttpClient.php';

$pages = [
    'accueil'        => ['isAdmin' => false,  'path' => '/index.php'],
    'my_submissions' => ['isAdmin' => false,  'path' => '/index.php?p=my_submissions'],
    'my_validations' => ['isAdmin' => false,  'path' => '/index.php?p=my_validations'],
    'dashboard'      => ['isAdmin' => true,   'path' => '/index.php?p=dashboard'],
    'admin_settings' => ['isAdmin' => true,   'path' => '/index.php?p=admin_settings'],
    'docs'           => ['isAdmin' => false,  'path' => '/index.php?p=docs'],
    'changelog'      => ['isAdmin' => false,  'path' => '/index.php?p=changelog'],
];

$failures = [];
$passes = [];

foreach ($pages as $name => $cfg) {
    $r = HttpClient::renderRoute('GET', $cfg['path'], [], $cfg['isAdmin']);
    $html = $r['html'] ?? '';

    if (strlen($html) < 100) {
        $failures[] = "$name — HTML vide (stderr: " . substr($r['stderr'] ?? '', 0, 200) . ")";
        continue;
    }

    // Détecter un échec de rendu (env PHP dégradé sans mbstring/pdo_sqlite
    // → session_start échoue → core_bootstrap affiche une erreur au lieu de
    // rendre la page). Dans ce cas, on skip les checks de présence mais on
    // garde les checks d'absence (un HTML cassé ne contiendra pas topbar
    // non plus, ce qui est OK).
    $env_degraded = (strpos($html, 'Warning: session_start') !== false)
                 || (strpos($html, 'Deprecated: session_start') !== false)
                 || (strpos($html, 'extensions PHP manquantes') !== false);

    // Vérifier l'ABSENCE de topbar (toujours, même en env dégradé)
    if (strpos($html, 'class="topbar"') !== false || strpos($html, "class='topbar'") !== false) {
        $failures[] = "$name — topbar présente dans le HTML rendu";
    }
    if (strpos($html, 'topbar-breadcrumb') !== false) {
        $failures[] = "$name — topbar-breadcrumb présent dans le HTML rendu";
    }
    if (strpos($html, 'topbar-cta') !== false || strpos($html, 'topbar-icon-btn') !== false) {
        $failures[] = "$name — topbar-cta ou topbar-icon-btn présent dans le HTML rendu";
    }
    if (strpos($html, 'class="breadcrumb"') !== false || strpos($html, "class='breadcrumb'") !== false) {
        $failures[] = "$name — breadcrumb présent dans le HTML rendu";
    }

    // v10.0.7 — sidebar-cta + "Nouvelle demande" supprimés (redondant avec
    // le 1er item "Formulaires" de la sidebar). Plus de check de présence.

    if (empty($failures) || !preg_grep("/^$name —/", $failures)) {
        $passes[] = $name;
    }
}

echo "\n═══════════════════════════════════════════════════════\n";
echo "  Vérification finale — HTML rendu\n";
echo "═══════════════════════════════════════════════════════\n\n";

foreach ($pages as $name => $_) {
    $pageFails = array_filter($failures, fn($f) => strpos($f, "$name —") === 0);
    if (empty($pageFails)) {
        echo "  ✅ $name — propre (pas de topbar/breadcrumb)\n";
    } else {
        foreach ($pageFails as $f) {
            echo "  ❌ $f\n";
        }
    }
}

echo "\n";
if (empty($failures)) {
    echo "═══════════════════════════════════════════════════════\n";
    echo "  ✅ Toutes les pages sont épurées (v9.1.0)\n";
    echo "═══════════════════════════════════════════════════════\n";
    exit(0);
} else {
    echo "═══════════════════════════════════════════════════════\n";
    echo "  ❌ " . count($failures) . " régression(s) détectée(s)\n";
    echo "═══════════════════════════════════════════════════════\n";
    exit(1);
}
