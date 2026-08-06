<?php
declare(strict_types=1);
/**
 * test_assets_cache.php — Test que les assets sont servis par PHP avec cache HTTP.
 *
 * Vérifie :
 *  1. Aucun asset online (CDN, Google Fonts, etc.) référencé dans le HTML.
 *  2. assets.php?type=css renvoie Content-Type: text/css + ETag + Cache-Control.
 *  3. assets.php?type=js&file=form-progress renvoie Content-Type: application/javascript.
 *  4. Le 304 Not Modified fonctionne (If-None-Match).
 *  5. Les pages HTML référencent <link href="assets.php"> (pas de <style> inline global).
 *
 * Usage : php tests/test_assets_cache.php
 *
 * Prérequis : un serveur PHP -S doit tourner sur 127.0.0.1:8899 (démarré par le test).
 */

require_once __DIR__ . '/test_bootstrap.php';

echo "── Test assets + cache HTTP ──\n";

$tests_passed = 0;
$tests_failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $tests_passed, $tests_failed;
    if ($ok) {
        echo "  ✅ $name\n";
        $tests_passed++;
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $tests_failed++;
    }
}

function http_get(string $url, array $headers = []): array {
    // Utiliser curl en ligne de commande (curl_init n'est pas toujours dispo en PHP)
    $headerArgs = [];
    foreach ($headers as $h) $headerArgs[] = '-H';
    foreach ($headers as $h) $headerArgs[] = $h;

    $cmd = array_merge(['curl', '-s', '--noproxy', 'localhost,127.0.0.1', '-D', '-', '-o', '-'], $headerArgs, [$url]);
    $cmdStr = '';
    foreach ($cmd as $arg) {
        $cmdStr .= ' ' . escapeshellarg($arg);
    }
    $response = shell_exec($cmdStr);

    if ($response === null || $response === '') {
        return ['status' => 0, 'headers' => '', 'body' => '', 'info' => []];
    }

    // Séparer headers et body
    $pos = strpos($response, "\r\n\r\n");
    if ($pos === false) $pos = strpos($response, "\n\n");
    if ($pos === false) {
        return ['status' => 0, 'headers' => '', 'body' => $response, 'info' => []];
    }
    $headersRaw = substr($response, 0, $pos);
    $body = substr($response, $pos + 4);  // +4 pour \r\n\r\n ou +2 pour \n\n (approximatif)

    // Extraire le status code
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headersRaw, $m)) {
        $status = (int)$m[1];
    }

    return [
        'status' => $status,
        'headers' => $headersRaw,
        'body' => $body,
        'info' => [],
    ];
}

// ── Démarrer un serveur PHP -S ──
$projectRoot = dirname(__DIR__);
$phpBin = PHP_BINARY;
// Tuer tout serveur existant
exec('pkill -9 -f "php -S 127.0.0.1:8899" 2>/dev/null');
sleep(1);

// Purger le cache CSS : reproduit le scénario "cache froid" (1er hit après
// déploiement) où assets.php recompile — le chemin qui a hébergé le bug
// filemtime() sur fichier absent. Sans cette purge, le test est non-déterministe
// (si le cache existe déjà, la branche recompilation n'est jamais exécutée).
foreach (glob($projectRoot . '/db/cache/assets_css_*.css') ?: [] as $cacheFile) {
    @unlink($cacheFile);
}

// display_errors=1 explicite : le bug (Warning filemtime) n'est visible dans le
// corps HTTP QUE si display_errors est On. En CI (php.ini production, Off par
// défaut) le warning part dans les logs serveur et le test ne peut rien voir.
// Avec display_errors=1, tout warning pollue le corps → l'assertion Test 2 le détecte.
$serverCmd = "$phpBin -d display_errors=1 -d error_reporting=E_ALL -S 127.0.0.1:8899 -t "
    . escapeshellarg($projectRoot) . " > /dev/null 2>&1 &";
exec($serverCmd);
sleep(2);

$baseUrl = 'http://127.0.0.1:8899';

// ── Test 1 : Aucun asset online dans le HTML ──
echo "\n── Test 1 : Aucun asset online (CDN, Google Fonts, etc.) ──\n";

$pages = ['/index.php', '/index.php?p=form&f=onboarding', '/health.php'];
$onlinePatterns = [
    'googleapis.com',
    'gstatic.com',
    'jsdelivr.net',
    'unpkg.com',
    'cdnjs.cloudflare.com',
    'cdn.',
    'bootstrapcdn',
    'fontawesome',
    'src="https://',
    'href="https://',
    'src="//',  // protocol-relative
];

foreach ($pages as $page) {
    $resp = http_get($baseUrl . $page, ['AUTH_USER: DREETS\admin']);
    $html = $resp['body'];

    $found = [];
    foreach ($onlinePatterns as $pattern) {
        if (stripos($html, $pattern) !== false) {
            $found[] = $pattern;
        }
    }
    check(
        "$page ne référence aucun asset online",
        empty($found),
        $found ? 'Patterns trouvés : ' . implode(', ', $found) : ''
    );
}

// ── Test 2 : assets.php?type=css — Content-Type + cache headers ──
echo "\n── Test 2 : assets.php?type=css — Content-Type + cache headers ──\n";

$resp = http_get($baseUrl . '/assets.php?type=css');
check("assets.php?type=css retourne HTTP 200", $resp['status'] === 200, "status={$resp['status']}");

$hasCssContentType = stripos($resp['headers'], 'content-type: text/css') !== false;
check("assets.php?type=css renvoie Content-Type: text/css", $hasCssContentType,
    $hasCssContentType ? '' : 'Headers: ' . $resp['headers']);

$hasEtag = stripos($resp['headers'], 'etag:') !== false;
check("assets.php?type=css renvoie un ETag", $hasEtag);

$hasCacheControl = stripos($resp['headers'], 'cache-control:') !== false;
check("assets.php?type=css renvoie Cache-Control", $hasCacheControl);

$hasLastModified = stripos($resp['headers'], 'last-modified:') !== false;
check("assets.php?type=css renvoie Last-Modified", $hasLastModified);

// Corps propre : le bug filemtime() (fichier cache absent) émettrait ici un
// "<br /><b>Warning</b>: ..." EN TÊTE du CSS — status/headers resteraient bons,
// seul le corps est corrompu. Vérifier que le corps est du CSS pur :
//  1. il commence par un commentaire CSS /* (tous les style_*.css commencent par /*)
//  2. il ne contient aucun pattern d'erreur PHP (display_errors=1 forcé par le test)
//     Format réel : "<b>Warning</b>:  message" — le </b> fait partie du pattern.
$hasPhpErrorInBody = preg_match('~<\s*b\s*>(?:Warning|Notice|Deprecated|Fatal error|Parse error)<\s*/b\s*>~i', $resp['body']) === 1;
check("assets.php?type=css renvoie un corps CSS pur (aucun warning PHP)",
    !$hasPhpErrorInBody,
    $hasPhpErrorInBody ? 'Body: ' . substr($resp['body'], 0, 300) : '');

check("assets.php?type=css renvoie un corps non vide", strlen($resp['body']) > 0,
    strlen($resp['body']) > 0 ? '' : 'Body vide');

// ── Test 3 : 304 Not Modified pour CSS ──
echo "\n── Test 3 : 304 Not Modified pour CSS ──\n";

// Extraire l'ETag de la réponse précédente
preg_match('/etag:\s*"?([^"\r\n]+)"?/i', $resp['headers'], $m);
$etag = $m[1] ?? '';
check("ETag extrait de la 1re réponse", $etag !== '', "ETag vide");

if ($etag !== '') {
    $resp304 = http_get($baseUrl . '/assets.php?type=css', ["If-None-Match: \"$etag\""]);
    check(
        "assets.php?type=css renvoie HTTP 304 avec If-None-Match",
        $resp304['status'] === 304,
        "status={$resp304['status']}, size=" . strlen($resp304['body'])
    );
    check(
        "304 response body est vide (0 byte transféré)",
        strlen($resp304['body']) === 0,
        "size=" . strlen($resp304['body'])
    );
}

// ── Test 4 : assets.php?type=js&file=form-progress — Content-Type + cache ──
echo "\n── Test 4 : assets.php?type=js — Content-Type + cache headers ──\n";

$respJs = http_get($baseUrl . '/assets.php?type=js&file=form-progress');
check("assets.php?type=js retourne HTTP 200", $respJs['status'] === 200, "status={$respJs['status']}");

$hasJsContentType = stripos($respJs['headers'], 'content-type: application/javascript') !== false
    || stripos($respJs['headers'], 'content-type: text/javascript') !== false;
check("assets.php?type=js renvoie Content-Type: application/javascript", $hasJsContentType,
    $hasJsContentType ? '' : 'Headers: ' . $respJs['headers']);

$hasJsEtag = stripos($respJs['headers'], 'etag:') !== false;
check("assets.php?type=js renvoie un ETag", $hasJsEtag);

// ── Test 5 : 304 Not Modified pour JS ──
echo "\n── Test 5 : 304 Not Modified pour JS ──\n";

preg_match('/etag:\s*"?([^"\r\n]+)"?/i', $respJs['headers'], $mJs);
$jsEtag = $mJs[1] ?? '';
if ($jsEtag !== '') {
    $respJs304 = http_get($baseUrl . '/assets.php?type=js&file=form-progress', ["If-None-Match: \"$jsEtag\""]);
    check(
        "assets.php?type=js renvoie HTTP 304 avec If-None-Match",
        $respJs304['status'] === 304,
        "status={$respJs304['status']}"
    );
}

// ── Test 6 : Les pages HTML référencent <link> vers assets.php ──
echo "\n── Test 6 : Pages HTML référencent assets.php via <link> ──\n";

$respIndex = http_get($baseUrl . '/index.php', ['AUTH_USER: DREETS\admin']);
$indexHtml = $respIndex['body'];

$hasLinkToAssets = strpos($indexHtml, '<link rel="stylesheet" href="assets.php?type=css">') !== false
    || strpos($indexHtml, "href='assets.php?type=css'") !== false;
check("index.php référence <link> vers assets.php?type=css", $hasLinkToAssets);

// Vérifier qu'il n'y a PLUS de <style> global (le gros bloc CSS inline)
// On accepte les petits <style> pour le page_css spécifique, mais pas le gros bloc style.php
$hasInlineGlobalStyle = strpos($indexHtml, '/* Design System') !== false
    || strpos($indexHtml, '--c-primary:') !== false
    || strpos($indexHtml, '.sidebar') !== false;
check(
    "index.php ne contient plus le gros <style> inline global (CSS maintenant externe)",
    !$hasInlineGlobalStyle,
    $hasInlineGlobalStyle ? 'Gros CSS inline encore présent — style.php toujours inclus' : ''
);

// ── Test 7 : form.php référence les JS via assets.php ──
echo "\n── Test 7 : form.php référence les JS via assets.php ──\n";

$respForm = http_get($baseUrl . '/index.php?p=form&f=onboarding', ['AUTH_USER: DREETS\admin']);
$formHtml = $respForm['body'];

$hasJsViaAssets = strpos($formHtml, 'assets.php?type=js&file=form-progress') !== false
    && strpos($formHtml, 'assets.php?type=js&file=form-conditions') !== false;
check("form.php référence les JS via assets.php?type=js", $hasJsViaAssets);

// Vérifier qu'il n'y a PLUS de références directes à assets/*.js
$hasDirectJsRef = strpos($formHtml, 'src="assets/form-progress.js"') !== false
    || strpos($formHtml, 'src="assets/form-conditions.js"') !== false;
check("form.php ne référence plus assets/*.js directement", !$hasDirectJsRef);

// ── Nettoyage ──
exec('pkill -9 -f "php -S 127.0.0.1:8899" 2>/dev/null');

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSULTATS : $tests_passed réussi(s) / $tests_failed échoué(s) / " . ($tests_passed + $tests_failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($tests_failed > 0 ? 1 : 0);
