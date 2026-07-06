<?php
declare(strict_types=1);
/**
 * test_confirm_action_dispatch.php — Vérifie que toutes les actions de
 * confirm_action.php sont bien dispatchées par un handler quelque part
 * dans le code (admin_forms_handlers.php OU pages/*.php).
 *
 * Bug historique (v10.0.4) : confirm_action.php POSTe 'remove_owner' mais
 * le dispatcher ne connaissait que 'delete_owner' → rien ne se passait.
 * Ce test aurait détecté le bug.
 *
 * Vérifie aussi que les params envoyés par confirm_action (hidden inputs)
 * correspondent aux clés $_POST attendues par le handler.
 *
 * Fichier : tests/test_confirm_action_dispatch.php
 */

require_once __DIR__ . '/test_bootstrap.php';

$passed = 0;
$failed = 0;
$violations = [];

function check_cad(string $name, bool $ok, array $details = []): void {
    global $passed, $failed, $violations;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name (" . count($details) . " violation(s))\n";
        foreach ($details as $d) {
            echo "     • $d\n";
        }
        $failed++;
        $violations = array_merge($violations, $details);
    }
}

echo "── Test : dispatch des actions confirm_action ──\n";

// ── Étape 1 : extraire les actions de confirm_action.php ──
$confirmFile = __DIR__ . '/../pages/confirm_action.php';
$confirmSrc = file_get_contents($confirmFile);
if ($confirmSrc === false) {
    echo "❌ Impossible de lire confirm_action.php\n";
    exit(1);
}

// Parser le tableau $actions_config pour extraire les clés d'action + params
// v10.0.5 — Approche simple : extraire le bloc $actions_config = [...]; et l'eval
// dans un sandbox (sécurisé car on contrôle le contenu du fichier).
preg_match('/\$actions_config\s*=\s*(\[.*?\]);/s', $confirmSrc, $m);
if (empty($m[1])) {
    echo "❌ Impossible de parser \$actions_config dans confirm_action.php\n";
    exit(1);
}
$array_src = $m[1];
// Eval le tableau dans un sandbox — sécurisé car confirm_action.php est un
// fichier du projet (pas une entrée utilisateur)
$actions_config = eval("return $array_src;");

// Extraire seulement action => params (on ignore les autres clés)
$actions_params = [];
foreach ($actions_config as $action => $cfg) {
    $actions_params[$action] = $cfg['params'] ?? [];
}
$actions_config = $actions_params;

echo "  Actions trouvées dans confirm_action.php : " . implode(', ', array_keys($actions_config)) . "\n\n";

// ── Étape 2 : extraire tous les case 'xxx' des dispatchers ──
$dispatchers = [
    'lib/admin_forms_handlers.php',
    'pages/admin_alerts.php',
    'pages/admin_access.php',
    'pages/submission_view.php',
    'pages/dashboard.php',
];

$all_cases = [];
foreach ($dispatchers as $disp) {
    $path = __DIR__ . '/../' . $disp;
    if (!file_exists($path)) continue;
    $src = file_get_contents($path);
    // Cherche case 'xxx' ou $action === 'xxx' ou $action == 'xxx'
    preg_match_all("/case\s+'([a-z_]+)'/", $src, $caseMatches);
    foreach ($caseMatches[1] as $c) {
        $all_cases[$c] = $disp;
    }
    preg_match_all("/\\\$action\s*===?\s*'([a-z_]+)'/", $src, $eqMatches);
    foreach ($eqMatches[1] as $c) {
        $all_cases[$c] = $disp;
    }
}

echo "  Cases trouvés dans les dispatchers : " . count($all_cases) . "\n\n";

// ── Test 1 : chaque action confirm_action a un case correspondant ──
echo "── Test 1 : chaque action confirm_action a un dispatcher ──\n";
$missing_dispatch = [];
foreach (array_keys($actions_config) as $action) {
    if (!isset($all_cases[$action])) {
        $missing_dispatch[] = "Action '$action' déclarée dans confirm_action.php mais AUCUN case trouvé dans : " . implode(', ', $dispatchers);
    }
}
check_cad('Toutes les actions confirm_action sont dispatchées', empty($missing_dispatch), $missing_dispatch);

// ── Test 2 : les params envoyés par confirm_action sont lus par le handler ──
echo "\n── Test 2 : les params envoyés correspondent aux \$_POST lus par les handlers ──\n";
$mismatch_params = [];

// Map : action confirm_action → nom du handler (par convention)
// v10.0.5 — pour chaque action, on sait quel handler la traite (soit via
// handle_admin_action_<action>, soit via un alias documenté)
$action_to_handler = [
    'cancel_submission'  => ['pages/submission_view.php', 'inline'],  // inline if/elseif
    'regenerate_token'   => ['pages/submission_view.php', 'inline'],
    'delete_rule'        => ['pages/admin_alerts.php', 'inline'],
    'delete_alert_log'   => ['pages/admin_alerts.php', 'inline'],
    'remove_admin'       => ['pages/admin_access.php', 'inline'],
    'remove_owner'       => ['lib/admin_forms_handlers_forms.php', 'handle_admin_action_delete_owner'],  // alias
    'delete_submission'  => ['pages/submission_view.php', 'inline'],  // v10.1.14
];

foreach ($actions_config as $action => $params) {
    if (empty($params)) continue;
    if (!isset($action_to_handler[$action])) {
        $mismatch_params[] = "Action '$action' : handler non documenté dans action_to_handler (test incomplet)";
        continue;
    }

    [$file, $handler_name] = $action_to_handler[$action];
    $path = __DIR__ . '/../' . $file;
    if (!file_exists($path)) continue;

    $src = file_get_contents($path);
    $posts_read = [];

    if ($handler_name === 'inline') {
        // Handler inline dans pages/*.php : extraire le bloc if/elseif ($action === 'xxx') { ... }
        // jusqu'au prochain elseif/else/}
        if (preg_match(
            '/(?:if|elseif)\s*\(\s*\$action\s*===?\s*\'' . preg_quote($action, '/') . "'[^{]*\{(.*?)(?:\n\s*\}\s*elseif|\n\s*\}\s*else\b|\n\s*\}\s*\n)/s",
            $src,
            $bm
        )) {
            preg_match_all('/\$_POST\[\'([^\']+)\'\]/', $bm[1], $postMatches);
            $posts_read = $postMatches[1];
        }
    } else {
        // Handler nommé dans lib/ : extraire function handle_admin_action_xxx() { ... }
        if (preg_match(
            '/function\s+' . preg_quote($handler_name, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n\}/s',
            $src,
            $fm
        )) {
            preg_match_all('/\$_POST\[\'([^\']+)\'\]/', $fm[1], $postMatches);
            $posts_read = $postMatches[1];
        }
    }

    $posts_read = array_unique($posts_read);

    // Vérifier que chaque param envoyé est lu par le handler
    // Alias explicitement documentés (chaque alias doit être justifié)
    // v10.0.5 — KNOWN_MISMATCHES : actions où le handler ne lit pas le param
    // envoyé par confirm_action, pour une raison documentée. Chaque entrée
    // doit avoir un commentaire expliquant POURQUOI.
    $KNOWN_ALIASES = [
        // action => [param_envoyé => [clés $_POST acceptées par le handler]]
    ];
    $KNOWN_MISMATCHES = [
        // action => [param => raison]
        'cancel_submission' => ['submission_id' => 'handler utilise $sub_id ($_GET[id]) au lieu de $_POST[submission_id]'],
        'delete_alert_log' => ['log_id' => 'handler purge tous les logs > N jours, ignore log_id'],
        'delete_submission' => ['submission_id' => 'handler utilise $sub_id ($_GET[id]) au lieu de $_POST[submission_id]'],
    ];

    // Si tous les params sont des mismatches documentés, on skip le check empty
    $all_params_documented = true;
    foreach ($params as $p) {
        if (!isset($KNOWN_MISMATCHES[$action][$p]) && !isset($KNOWN_ALIASES[$action][$p])) {
            $all_params_documented = false;
            break;
        }
    }

    if (empty($posts_read) && !$all_params_documented) {
        $mismatch_params[] = "Action '$action' : handler '$handler_name' trouvé mais aucun \$_POST lu (bug?)";
        continue;
    }

    foreach ($params as $p) {
        // Skip si mismatch documenté
        if (isset($KNOWN_MISMATCHES[$action][$p])) continue;

        $accepted_keys = $KNOWN_ALIASES[$action][$p] ?? [$p];
        $found = false;
        foreach ($accepted_keys as $k) {
            if (in_array($k, $posts_read, true)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $mismatch_params[] = "Action '$action' envoie param '$p' mais le handler ne lit aucun \$_POST['" . implode("', '\$_POST['", $accepted_keys) . "'] (lit : " . implode(', ', $posts_read) . ")";
        }
    }
}
check_cad('Les params envoyés par confirm_action sont lus par les handlers', empty($mismatch_params), $mismatch_params);

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  DISPATCH TESTS — " . (empty($violations) ? "✅ AUCUNE VIOLATION" : "❌ " . count($violations) . " violation(s)") . "\n";
echo "  $passed test(s) réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
