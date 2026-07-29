<?php
declare(strict_types=1);
/**
 * test_confirm_action_dispatch.php — Vérifie que chaque action déclarée dans
 * ConfirmActionController route réellement (via $postUrl) vers un contrôleur
 * qui la traite en POST.
 *
 * Réécrit le 2026-07-29. L'ancienne version référençait l'architecture
 * pré-refactor (pages/confirm_action.php, pages/*.php — supprimés lors de la
 * migration vers src/Controller/, commit 1871b71, v10.6.0) et faisait un
 * `continue` silencieux dès qu'un fichier attendu était absent. Résultat :
 * depuis la migration, ce test ne vérifiait plus RIEN du tout tout en
 * continuant de rapporter un succès (0 violation).
 *
 * Bug qu'une version à jour de ce test aurait dû détecter (trouvé le
 * 2026-07-29) : delete_submission et remove_admin n'avaient pas de $postUrl
 * explicite dans ConfirmActionController → retombaient sur $from (fourni par
 * l'appelant), qui ne pointait pas toujours vers un contrôleur gérant
 * l'action en POST → confirmation affichée, clic "confirmer" sans aucun
 * effet, silencieusement.
 *
 * Principe directeur de ce fichier : toute impossibilité de vérifier
 * (fichier introuvable, regex qui ne matche plus, structure inattendue) est
 * un CRASH DUR — exit(1) immédiat avec message explicite — jamais un
 * `continue` qui laisse le test terminer en rapportant un succès alors qu'il
 * n'a rien vérifié. Un test qui peut passer au vert sans avoir réellement
 * exécuté ses vérifications est pire qu'aucun test : il donne une fausse
 * confiance et peut laisser passer une régression jusqu'en E2E, voire en
 * production.
 */

/** @return never */
function cad_crash(string $message)
{
    fwrite(STDERR, "\n");
    fwrite(STDERR, "💥 CRASH — test_confirm_action_dispatch.php ne peut pas continuer\n");
    fwrite(STDERR, "   $message\n");
    fwrite(STDERR, "   (voir le commentaire d'en-tête de ce fichier : un `continue` silencieux\n");
    fwrite(STDERR, "    ici équivaudrait à un faux succès — c'est exactement le bug qui a\n");
    fwrite(STDERR, "    laissé ce test ne rien vérifier depuis la migration v10.6.0)\n\n");
    exit(1);
}

function cad_read_or_crash(string $path): string
{
    if (!is_file($path)) {
        cad_crash("Fichier introuvable : $path");
    }
    $src = file_get_contents($path);
    if ($src === false) {
        cad_crash("Impossible de lire : $path");
    }
    return $src;
}

echo "── Test : routage des actions ConfirmActionController ──\n\n";

$repoRoot = __DIR__ . '/..';

// ── Étape 1 : extraire $actionsConfig de ConfirmActionController ──
$controllerPath = $repoRoot . '/src/Controller/ConfirmActionController.php';
$controllerSrc = cad_read_or_crash($controllerPath);

if (!preg_match('/\$actionsConfig\s*=\s*(\[.*?\]);/s', $controllerSrc, $m)) {
    cad_crash("Impossible de parser \$actionsConfig dans $controllerPath — la regex ne matche plus la structure du fichier, ce test doit être mis à jour AVANT d'être considéré fiable.");
}
$actionsConfig = eval("return {$m[1]};");
if (!is_array($actionsConfig) || $actionsConfig === []) {
    cad_crash("\$actionsConfig est vide ou n'est pas un tableau après eval — ce test doit être mis à jour.");
}
echo "  Actions déclarées : " . implode(', ', array_keys($actionsConfig)) . "\n\n";

// ── Étape 2 : extraire les postUrl explicites ──
// (if|elseif ($action === 'xxx' ...) { ... $postUrl = 'index.php?p=YYY...'; ... })
if (!preg_match_all(
    "/(?:if|elseif)\s*\(\s*\\\$action\s*===\s*'([a-z_]+)'.*?\)\s*\{(.*?)\n\s*\}(?=\s*(?:elseif|else\b|\n))/s",
    $controllerSrc,
    $branches,
    PREG_SET_ORDER
)) {
    cad_crash("Aucune branche if/elseif (\$action === ...) trouvée dans ConfirmActionController — la regex ne matche plus, ce test doit être mis à jour AVANT d'être considéré fiable.");
}
$explicitTargets = [];
foreach ($branches as $b) {
    $action = $b[1];
    $body = $b[2];
    if (preg_match("/\\\$postUrl\s*=\s*'index\.php\?p=([a-z_]+)/", $body, $pm)) {
        $explicitTargets[$action] = $pm[1];
    }
}
echo "  postUrl explicite trouvé pour : " . (empty($explicitTargets) ? '(aucun)' : implode(', ', array_map(
    static fn($a) => "{$a}→p={$explicitTargets[$a]}",
    array_keys($explicitTargets)
))) . "\n\n";

// ── Étape 3 : actions SANS postUrl explicite ──
// Elles retombent sur $from (fourni par l'appelant du lien confirm_action).
// Chaque entrée ci-dessous doit être vérifiée MANUELLEMENT : lister toutes
// les pages qui génèrent un lien vers cette action, et confirmer qu'elles
// pointent bien (via from=) vers un contrôleur qui gère l'action en POST.
// N'AJOUTER une action ici qu'après cette vérification — sinon utiliser un
// postUrl explicite dans ConfirmActionController (plus sûr, statiquement
// vérifiable, c'est la voie recommandée pour toute nouvelle action).
$RELIES_ON_CALLER_FROM = [
    // Vérifié 2026-07-29 : SubmissionViewController (from=submission_view) ET
    // DashboardController (from=dashboard) gèrent tous les deux
    // cancel_submission en POST — fonctionne quel que soit l'appelant.
    'cancel_submission' => ['submission_view', 'dashboard'],
    // Vérifié 2026-07-29 : seul le lien depuis DashboardController::renderContent
    // génère cette action, avec from= pointant toujours vers dashboard, qui
    // gère regenerate_token en POST.
    'regenerate_token' => ['dashboard'],
];

$missingRouting = [];
foreach (array_keys($actionsConfig) as $action) {
    if (isset($explicitTargets[$action])) {
        continue;
    }
    if (isset($RELIES_ON_CALLER_FROM[$action])) {
        continue;
    }
    $missingRouting[] = $action;
}
if ($missingRouting !== []) {
    cad_crash(
        "Action(s) sans routage vérifiable : " . implode(', ', $missingRouting) . "\n" .
        "   → Soit ajouter un postUrl explicite dans ConfirmActionController (recommandé),\n" .
        "   → soit documenter dans \$RELIES_ON_CALLER_FROM (ce fichier) après avoir\n" .
        "     vérifié manuellement TOUS les callers connus de cette action.\n" .
        "   C'est exactement l'absence de cette vérification qui a causé le bug\n" .
        "   delete_submission/remove_admin du 2026-07-29 (non détecté avant la\n" .
        "   réécriture de ce test)."
    );
}

// ── Étape 4 : résoudre page → contrôleur via le mapping de index.php ──
$indexSrc = cad_read_or_crash($repoRoot . '/index.php');
if (!preg_match_all("/'([a-z_]+)'\s*=>\s*\\\\App\\\\Controller\\\\(\w+)::class/", $indexSrc, $cm, PREG_SET_ORDER)) {
    cad_crash("Impossible de parser le mapping page→contrôleur dans index.php — la regex ne matche plus, ce test doit être mis à jour AVANT d'être considéré fiable.");
}
$pageToController = [];
foreach ($cm as $c) {
    $pageToController[$c[1]] = $c[2];
}
if ($pageToController === []) {
    cad_crash("Le mapping page→contrôleur extrait de index.php est vide — la regex ne matche plus.");
}

// ── Étape 5 : chaque action doit avoir un handler POST sur SA page cible ──
//
// Remappings connus, chacun vérifié manuellement le 2026-07-29 — ne pas
// ajouter d'entrée ici "pour faire passer le test" sans avoir confirmé par
// lecture du code que le handler réel existe bien :
// - persona_start/persona_stop : $postUrl réécrit action= dans sa propre
//   query string ('index.php?p=persona&action=start&...'), et
//   PersonaController lit exclusivement $_GET['action'] (jamais $_POST) —
//   donc le nom réellement recherché par le contrôleur est 'start'/'stop',
//   pas 'persona_start'/'persona_stop'.
// - remove_owner : délégué à AdminFormsHandlers::dispatch(), un tableau de
//   dispatch ('remove_owner' => AdminRecipientHandler::handleDeleteOwner())
//   et non un if/switch sur $action — le nom cherché est le même, mais le
//   fichier à inspecter n'est pas le contrôleur lui-même.
$KNOWN_ACTION_REMAP = [
    'persona_start' => ['searchFor' => 'start', 'files' => ['src/Controller/PersonaController.php']],
    'persona_stop'  => ['searchFor' => 'stop',  'files' => ['src/Controller/PersonaController.php']],
    'remove_owner'  => ['searchFor' => 'remove_owner', 'files' => ['src/Controller/AdminFormsController.php', 'src/Controller/AdminFormsHandlers.php']],
];

function cad_file_has_handler(string $file, string $searchFor): bool
{
    $src = file_get_contents($file);
    if ($src === false) {
        cad_crash("Impossible de lire : $file");
    }
    $q = preg_quote($searchFor, '/');
    return preg_match(
        "/\\\$action\s*===\s*'$q'|\\\$_POST\[\s*'action'\s*\]\s*===\s*'$q'|\\\$_GET\[\s*'action'\s*\]\s*===\s*'$q'|'$q'\s*=>/",
        $src
    ) === 1;
}

function cad_controller_has_handler(string $repoRoot, string $controllerFile, string $action, array $remap): bool
{
    if (isset($remap[$action])) {
        foreach ($remap[$action]['files'] as $relFile) {
            if (cad_file_has_handler($repoRoot . '/' . $relFile, $remap[$action]['searchFor'])) {
                return true;
            }
        }
        return false;
    }
    return cad_file_has_handler($controllerFile, $action);
}

$passed = 0;
$violations = [];

foreach (array_keys($actionsConfig) as $action) {
    $targetPages = $explicitTargets[$action] ?? null;
    $targetPages = $targetPages !== null ? [$targetPages] : $RELIES_ON_CALLER_FROM[$action];

    foreach ($targetPages as $page) {
        if (!isset($pageToController[$page])) {
            cad_crash("Action '$action' route vers p=$page, mais cette page n'existe pas dans le mapping index.php (renommée ? supprimée ?).");
        }
        $controllerClass = $pageToController[$page];
        $controllerFile = $repoRoot . '/src/Controller/' . $controllerClass . '.php';
        if (!is_file($controllerFile)) {
            cad_crash("Contrôleur '$controllerClass' introuvable ($controllerFile) pour l'action '$action' (page p=$page).");
        }

        if (cad_controller_has_handler($repoRoot, $controllerFile, $action, $KNOWN_ACTION_REMAP)) {
            $passed++;
            echo "  ✅ $action → p=$page ($controllerClass)\n";
        } else {
            $violations[] = "Action '$action' route vers p=$page ($controllerClass) mais ce contrôleur n'a AUCUN handler POST pour '$action'.";
            echo "  ❌ $action → p=$page ($controllerClass) : PAS DE HANDLER\n";
        }
    }
}

echo "\n═══════════════════════════════════════════════════\n";
echo "  ROUTING TESTS — " . ($violations === [] ? "✅ AUCUNE VIOLATION" : "❌ " . count($violations) . " violation(s)") . "\n";
foreach ($violations as $v) {
    echo "    • $v\n";
}
echo "  $passed vérification(s) réussie(s) / " . count($violations) . " échouée(s)\n";
echo "═══════════════════════════════════════════════════\n";
exit($violations === [] ? 0 : 1);
