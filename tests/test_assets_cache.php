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
 * Prérequis : un serveur PHP -S doit tourner sur 127.0.0.1:8767 (démarré par le test).
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

    // Timeouts bornés : shell_exec() attend la fin de curl — sans --max-time,
    // une requête qui pend (serveur vivant mais bloqué : verrou SQLite, boucle
    // côté PHP…) bloque le test INDÉFINIMENT. C'est la cause du hang constaté.
    $cmd = array_merge(['curl', '-s', '--noproxy', 'localhost,127.0.0.1',
        '--connect-timeout', '5', '--max-time', '20',
        '-D', '-', '-o', '-'], $headerArgs, [$url]);
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
    $sepLen = substr($response, $pos, 4) === "\r\n\r\n" ? 4 : 2;
    $body = substr($response, $pos + $sepLen);

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
// Port dans la plage de test 8760-8799 (kill_port() refuse hors plage)
$PORT = 8767;
// Tuer tout serveur existant sur ce port (cross-platform : netstat+taskkill sur Windows)
kill_port($PORT);
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
// Démarrage cross-platform :
//  - Windows : proc_open() avec commande en TABLEAU (PHP 7.4+, CreateProcess sans
//    shell intermédiaire) — stdout/stderr redirigés vers le log temporaire via le
//    descripteur ['file', ...], aucun quoting cmd.exe. Remplace COM/WScript.Shell
//    qui dépend de l'extension com_dotnet (activée uniquement dans cli\php.ini :
//    absente des contextes sans PHP_INI_SCAN_DIR → « Class "COM" not found »).
//    Pas de proc_close() (il attendrait la fin du serveur) : le processus tourne
//    en arrière-plan et survit à la fin du script ; il est arrêté par kill_port().
//  - Linux : shell_exec("... &") — OK sous bash ; BLOQUE sous cmd.exe, ne jamais
//    l'utiliser sur Windows.
$serverProc = null; // handle proc_open du serveur (Windows) ; null sous Linux
$logFile = test_temp_dir() . '/php_server_assets.log';
if (PHP_OS_FAMILY === 'Windows') {
    $proc = proc_open(
        [$phpBin, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL',
            '-S', '127.0.0.1:' . $PORT, '-t', $projectRoot],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
        $pipes
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "Impossible de démarrer le serveur PHP -S (proc_open) — log : $logFile\n");
        exit(1);
    }
    // stdin inutilisé par php -S : refermer côté parent.
    fclose($pipes[0]);
    $serverProc = $proc;
} else {
    $serverCmd = $phpBin . ' -d display_errors=1 -d error_reporting=E_ALL -S 127.0.0.1:' . $PORT
        . ' -t ' . escapeshellarg($projectRoot)
        . ' > ' . escapeshellarg($logFile) . ' 2>&1';
    shell_exec($serverCmd . ' &');
}
// Arrêt propre et borné du serveur : proc_terminate() (non bloquant) puis
// kill_port() en filet. JAMAIS proc_close() tant que le serveur peut être
// vivant : il attendrait la fin du processus et bloquerait le test.
$stopServer = static function (): void {
    global $serverProc, $PORT;
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
    }
    kill_port($PORT);
};
// Filet anti-orphelin : si le test se termine sans passer par le nettoyage
// final (exit anticipé), le serveur PHP -S ne doit pas rester orphelin sur
// le port de test. NB : en cas de fatal, le filet anti-masquage de
// test_bootstrap.php (enregistré AVANT celui-ci) appelle exit(1) dans sa
// propre shutdown function, ce qui court-circuite celle-ci (comportement
// vérifié empiriquement) — l'orphelin éventuel est alors nettoyé par le
// kill_port() de tête du run suivant.
register_shutdown_function(static function (): void {
    global $stopServer;
    $stopServer();
});

$baseUrl = 'http://127.0.0.1:' . $PORT;

// Attente BORNÉE de la disponibilité du serveur — remplace le sleep(2) fixe,
// insuffisant si le serveur est lent à démarrer et muet s'il ne démarre
// jamais. Sonde curl toutes les 300 ms pendant 20 s max, chaque sonde étant
// elle-même bornée (connect 1 s, total 3 s). Tout code HTTP (même 401/404)
// prouve que le serveur écoute ; curl exit 0 = serveur joignable.
$serverReady = false;
$readinessDeadline = microtime(true) + 20.0;
$probeTarget = escapeshellarg($baseUrl . '/health.php');
$probeOut = escapeshellarg(test_temp_dir() . '/assets_probe.out');
while (microtime(true) < $readinessDeadline) {
    exec('curl -s --noproxy localhost,127.0.0.1 --connect-timeout 1 --max-time 3'
        . ' -o ' . $probeOut . ' ' . $probeTarget, $probeOutLines, $probeCode);
    if ($probeCode === 0) {
        $serverReady = true;
        break;
    }
    usleep(300000);
}
if (!$serverReady) {
    fwrite(STDERR, "Serveur PHP -S injoignable sur 127.0.0.1:$PORT après 20 s — log : $logFile\n");
    $stopServer();
    exit(1);
}

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
        $found === [],
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

check("assets.php?type=css renvoie un corps non vide", (string) $resp['body'] !== '',
    (string) $resp['body'] !== '' ? '' : 'Body vide');

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
        (string) $resp304['body'] === '',
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

$hasLinkToAssets = preg_match('/<link rel="stylesheet" href="[^"]*assets\.php\?type=css&v=\d+\.\d+\.\d+">/', $indexHtml) === 1
    || preg_match("/href='[^']*assets\.php\?type=css&v=\d+\.\d+\.\d+'/", $indexHtml) === 1;
check("index.php référence <link> vers assets.php?type=css&v=<version>", $hasLinkToAssets);

// Vérifier qu'il n'y a PLUS de <style> global (le gros bloc CSS inline)
// On accepte les petits <style> pour le page_css spécifique, mais pas le gros bloc style.php
$hasInlineGlobalStyle = str_contains($indexHtml, '/* Design System')
    || str_contains($indexHtml, '--c-primary:')
    || str_contains($indexHtml, '.sidebar');
check(
    "index.php ne contient plus le gros <style> inline global (CSS maintenant externe)",
    !$hasInlineGlobalStyle,
    $hasInlineGlobalStyle ? 'Gros CSS inline encore présent — style.php toujours inclus' : ''
);

// ── Test 7 : form.php référence les JS via assets.php ──
echo "\n── Test 7 : form.php référence les JS via assets.php ──\n";

$respForm = http_get($baseUrl . '/index.php?p=form&f=onboarding', ['AUTH_USER: DREETS\admin']);
$formHtml = $respForm['body'];

$hasJsViaAssets = preg_match('/assets\.php\?type=js&file=form-progress&v=\d+\.\d+\.\d+/', $formHtml) === 1
    && preg_match('/assets\.php\?type=js&file=form-conditions&v=\d+\.\d+\.\d+/', $formHtml) === 1;
check("form.php référence les JS via assets.php?type=js&v=<version>", $hasJsViaAssets);

// Vérifier qu'il n'y a PLUS de références directes à assets/*.js
$hasDirectJsRef = str_contains($formHtml, 'src="assets/form-progress.js"')
    || str_contains($formHtml, 'src="assets/form-conditions.js"');
check("form.php ne référence plus assets/*.js directement", !$hasDirectJsRef);

// ── Nettoyage ──
// proc_terminate() sur le handle proc_open puis kill_port() en filet —
// ni l'un ni l'autre n'attendent (jamais proc_close() sur un process
// potentiellement vivant).
$stopServer();

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSULTATS : $tests_passed réussi(s) / $tests_failed échoué(s) / " . ($tests_passed + $tests_failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";

// Contrat B-HARNESS (test_bootstrap.php) : le résumé étant imprimé ci-dessus,
// marquer la fin nominale pour que le filet anti-masquage ne force pas
// exit(1) — sinon ce test sort avec le code 1 MÊME EN CAS DE SUCCÈS TOTAL
// (le filet réimprime des compteurs bootstrap 0/0 et redresse le code à 1).
$GLOBALS['_test_summary_printed'] = true;

exit($tests_failed > 0 ? 1 : 0);
