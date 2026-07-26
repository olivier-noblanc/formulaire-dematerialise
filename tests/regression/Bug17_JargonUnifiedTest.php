<?php
declare(strict_types=1);
/**
 * Bug 17 — JargonService et HtmlService::tJargon() avaient deux dictionnaires divergents
 *
 * Symptôme : HtmlService::tJargon('Token') retournait 'Token' (non traduit),
 * mais t_jargon('Token') retournait 'Lien de validation'. Idem pour CSRF
 * ('Jeton de sécurité (CSRF)' vs 'Code de sécurité'), SI, SMTP... Le même
 * label utilisateur s'affichait différemment selon la page selon que la
 * page appelait App::html()->tJargon() ou t_jargon().
 *
 * Fix 2026-07-26 : HtmlService::tJargon() délègue à JargonService::translate()
 * (source unique de vérité). JargonService enrichi des acronymes techniques
 * manquants (DSI, RH, DREETS, etc.) et CSRF/SI alignés sur la version la plus
 * informative.
 *
 * Test : vérifier que les deux points d'entrée retournent le même résultat
 * pour 'Token', 'CSRF', 'SI' — les 3 cas les plus visibles de la divergence.
 *
 * Fichier : tests/regression/Bug17_JargonUnifiedTest.php
 *
 * @package tests\regression
 */

function run_bug17_test(): bool {
    // Charger les deux implémentations
    $root = dirname(__DIR__, 2);
    require_once $root . '/vendor/autoload.php';
    require_once $root . '/src/lib_wrappers.php';
    require_once $root . '/lib/core_bootstrap.php';

    $htmlService = new \App\Render\HtmlService();

    $testCases = [
        'Token'  => ['expected_contains' => 'Lien de validation'],
        'CSRF'   => ['expected_contains' => 'Jeton de sécurité'],
        'SI'     => ['expected_contains' => 'Système'],
        'RGPD'   => ['expected_contains' => 'Protection des données'],
        'SMTP'   => ['expected_contains' => 'Serveur email'],
    ];

    $failures = [];
    foreach ($testCases as $input => $cfg) {
        $viaHtmlService = $htmlService->tJargon($input);
        $viaJargonService = \App\Render\JargonService::translate($input);
        $viaGlobal = t_jargon($input);

        // 1. HtmlService doit maintenant traduire Token (avant: retournait 'Token')
        if (!str_contains($viaHtmlService, $cfg['expected_contains'])) {
            $failures[] = "HtmlService::tJargon('{$input}') = '{$viaHtmlService}' — attendu contenir '{$cfg['expected_contains']}'";
        }

        // 2. Les 3 points d'entrée doivent retourner la même chose
        if ($viaHtmlService !== $viaJargonService) {
            $failures[] = "HtmlService::tJargon('{$input}')='{$viaHtmlService}' ≠ JargonService::translate='{$viaJargonService}'";
        }
        if ($viaHtmlService !== $viaGlobal) {
            $failures[] = "HtmlService::tJargon('{$input}')='{$viaHtmlService}' ≠ t_jargon='{$viaGlobal}'";
        }
    }

    if ($failures !== []) {
        echo "  ❌ Bug17 — Divergence jargon détectée :\n";
        foreach ($failures as $f) {
            echo "     - {$f}\n";
        }
        return false;
    }

    echo "  ✅ Bug17 — Dictionnaire jargon unifié : HtmlService::tJargon() = t_jargon() = JargonService::translate()\n";
    return true;
}
