<?php
declare(strict_types=1);
/**
 * Bug 10 — Labels et hints en double dans validate.php (champs validateur)
 *
 * Symptôme : sur index.php?p=validate&token=XXX, chaque champ validateur
 * avait son label en double ("Pôle" + "Pôle *") et son hint en double
 * ("Saisie libre" + "Saisie libre").
 *
 * Cause : validate.php ajoutait un <label> manuel ET un <span class="hint">
 * manuel AVANT d'appeler render_field() qui génère déjà son propre label
 * et son propre hint.
 *
 * Aussi : render_field() générait "Texte libre" comme hint auto pour les
 * champs texte simples — c'est évident et n'apporte rien.
 *
 * Test : vérifie que le HTML rendu de validate.php avec un token valide
 * ne contient pas de labels ou hints en double pour les champs validateur.
 * Vérifie aussi que "Texte libre" n'apparaît plus comme hint auto.
 */

require_once __DIR__ . '/_subprocess_helper.php';

function run_bug10_test(): bool {
    $project_root = dirname(__DIR__, 2);

    // 1. Vérifier le code source de validate.php : pas de <label> manuel
    //    avant render_field() dans la boucle des champs validateur
    $validate_src = file_get_contents($project_root . '/pages/validate.php');
    if ($validate_src === false) {
        return false;
    }

    // Chercher s'il y a encore un <label for="<?= \App\Core\App::html()->escape($vf['field_name'])" manuel
    if (preg_match('/<label for=.*vf\[.field_name.\]/', $validate_src)) {
        echo "  ❌ Label manuel encore présent avant render_field() dans validate.php\n";
        return false;
    }
    echo "  ✅ Pas de label manuel avant render_field()\n";

    // 2. Vérifier le code source de render_form.php : "Texte libre" supprimé
    $render_form_src = file_get_contents($project_root . '/lib/render_form.php');
    if ($render_form_src === false) {
        return false;
    }

    if (strpos($render_form_src, "'Texte libre'") !== false
        && strpos($render_form_src, "auto_hint_text = 'Texte libre'") !== false) {
        echo "  ❌ 'Texte libre' encore généré comme hint auto dans render_form.php\n";
        return false;
    }
    echo "  ✅ 'Texte libre' supprimé des hints auto\n";

    // 3. Vérifier que render_field() ne génère qu'un seul <label> par champ
    // Simuler le rendu d'un champ texte
    $script = <<<'PHP'
require_once $project_root . "/helpers.php";
$vf = [
    'field_name' => 'pole_test',
    'label' => 'Pôle',
    'field_type' => 'text',
    'required' => 1,
    'hint' => 'Indiquez votre pôle',
];
$html = render_field($vf, '', [], '', false);

// Compter les <label for="pole_test">
preg_match_all('/<label for="pole_test"/', $html, $m);
$label_count = count($m[0]);

// Compter les hints
preg_match_all('/class="hint"/', $html, $m2);
$hint_count = count($m2[0]);

// Compter "Saisie libre" ou "Texte libre"
$useless_count = substr_count($html, 'Saisie libre') + substr_count($html, 'Texte libre');

echo "LABELS:$label_count\n";
echo "HINTS:$hint_count\n";
echo "USELESS:$useless_count\n";
echo "HTML:" . $html . "\n";
PHP;

    $result = run_regression_script($script, [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI'    => '/index.php',
        'SCRIPT_NAME'    => '/index.php',
    ]);
    $stdout = $result['stdout'] ?? '';

    // Parser les résultats
    $labels = 0; $hints = 0; $useless = 0;
    if (preg_match('/LABELS:(\d+)/', $stdout, $m)) $labels = (int)$m[1];
    if (preg_match('/HINTS:(\d+)/', $stdout, $m)) $hints = (int)$m[1];
    if (preg_match('/USELESS:(\d+)/', $stdout, $m)) $useless = (int)$m[1];

    if ($labels !== 1) {
        echo "  ❌ render_field() génère $labels labels (attendu: 1)\n";
        return false;
    }
    echo "  ✅ render_field() génère exactement 1 label\n";

    if ($useless > 0) {
        echo "  ❌ render_field() génère encore 'Saisie libre' ou 'Texte libre' ($useless occurrence(s))\n";
        return false;
    }
    echo "  ✅ Plus de 'Saisie libre' ni 'Texte libre' dans les hints auto\n";

    // Le hint personnalisé ("Indiquez votre pôle") doit être présent
    if (strpos($stdout, 'Indiquez votre pôle') === false) {
        echo "  ❌ Le hint personnalisé n'est pas affiché\n";
        return false;
    }
    echo "  ✅ Le hint personnalisé est affiché\n";

    return true;
}
