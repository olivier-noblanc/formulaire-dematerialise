<?php
declare(strict_types=1);
/**
 * Bug 11 — Liens d'email pointant vers des 404 (validate.php n'existe plus)
 *
 * Symptôme : les emails de validation contenaient des liens vers
 * validate.php, admin_access.php, dashboard.php — qui n'existent plus
 * à la racine depuis le front controller (v8.0.0). → 404 pour l'utilisateur.
 *
 * Cause : les fonctions qui construisent les URLs d'email utilisaient
 * resolve_base_url() . '/validate.php?token=...' au lieu de
 * resolve_base_url() . '/index.php?p=validate&token=...'
 *
 * Test : vérifie que TOUS les liens générés par le code email utilisent
 * index.php?p=xxx et non xxx.php directement.
 */

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/../helpers.php';

if (!defined('TEST_MODE')) define('TEST_MODE', true);

echo "── Test Bug11 : liens d'email pointent vers index.php?p= ──\n";

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failed++;
    }
}

// ── Test 1 : build_mail_html génère un lien index.php?p=validate ──
echo "\n── Test 1 : build_mail_html — lien de validation ──\n";

$submission = [
    'data' => json_encode(['nom' => 'Test', 'prenom' => 'Agent']),
    'form_label' => 'Test Form',
];
$html = \App\Core\App::mail()->buildMailHtml($submission, 'Étape test', 'abc123token');

// Le lien doit contenir index.php?p=validate
$hasGoodUrl = strpos($html, 'index.php?p=validate&token=abc123token') !== false;
check('Lien de validation utilise index.php?p=validate', $hasGoodUrl,
    $hasGoodUrl ? '' : 'URL trouvée: ' . (preg_match('/href="([^"]*validate[^"]*)"/', $html, $m) ? $m[1] : 'non trouvée'));

// Le lien ne doit PAS contenir validate.php directement
$hasBadUrl = strpos($html, '/validate.php') !== false;
check('Lien ne contient PAS /validate.php', !$hasBadUrl,
    $hasBadUrl ? 'Still using /validate.php' : '');

// ── Test 2 : render_email_template ne contient pas de .php ──
echo "\n── Test 2 : render_email_template — pas de .php direct ──\n";

$html2_raw = \App\Core\App::mail()->renderEmailTemplate('Test', '<p>Body</p>');
// Nettoyer les warnings PHP qui peuvent apparaître avant le DOCTYPE
$docPos = strpos($html2_raw, '<!DOCTYPE');
if ($docPos !== false) {
    $html2 = substr($html2_raw, $docPos);
} else {
    $html2 = $html2_raw;
}
$hasDirectPhp = preg_match('/href="[^"]*\/[a-z_]+\.php[^"]*"/', $html2) > 0;
check('Template email ne contient pas de lien .php direct', !$hasDirectPhp);

// ── Test 3 : Code source — aucune URL .php dans les fonctions email ──
echo "\n── Test 3 : Code source — aucune URL .php dans fonctions email ──\n";

$filesToCheck = [
    'lib/mail.php',
    'lib/auth.php',
    'lib/tokens.php',
    'lib/workflow.php',
    'alert_check.php',
    'remind.php',
    'src/Mail/MailService.php',
];

// Pages qui n'existent plus à la racine
$movedPages = ['validate', 'admin_access', 'admin_alerts', 'admin_forms', 'admin_settings',
               'backup', 'changelog', 'confirm_action', 'dashboard', 'docs', 'download',
               'form', 'form_preview', 'form_tracking', 'health', 'monitoring',
               'my_submissions', 'my_validations', 'rgpd', 'screenshot', 'stats',
               'submission_view'];

$badUrls = [];
foreach ($filesToCheck as $file) {
    $path = __DIR__ . '/../' . $file;
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    foreach ($movedPages as $page) {
        // Chercher '/page.php' mais PAS 'index.php' ni dans un commentaire
        if (preg_match_all("/'\/{$page}\.php/", $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                // Vérifier si c'est dans un commentaire
                $lineStart = strrpos(substr($content, 0, $match[1]), "\n") + 1;
                $line = substr($content, $lineStart, $match[1] - $lineStart + strlen($match[0]));
                if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) continue;
                $lineNum = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                $badUrls[] = "$file:$lineNum → '/{$page}.php'";
            }
        }
    }
}

check('Aucune URL .php dans le code email (toutes utilisent index.php?p=)', empty($badUrls),
    $badUrls ? implode('; ', array_slice($badUrls, 5)) : '');

// ── Test 4 : URL de validation résolvable ──
echo "\n── Test 4 : URL de validation complète et correcte ──\n";

preg_match('/href="([^"]*)"/', $html, $hrefMatch);
if (!empty($hrefMatch[1])) {
    $url = $hrefMatch[1];
    // L'URL doit contenir index.php, p=validate, et token=
    $hasIndex = strpos($url, 'index.php') !== false;
    $hasP = strpos($url, 'p=validate') !== false;
    $hasToken = strpos($url, 'token=abc123token') !== false;
    check('URL contient index.php', $hasIndex);
    check('URL contient p=validate', $hasP);
    check('URL contient token=abc123token', $hasToken);
} else {
    check('URL de validation trouvée dans le HTML', false, 'Aucun href trouvé');
}

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSULTATS : $passed réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
