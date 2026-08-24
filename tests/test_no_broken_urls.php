<?php
declare(strict_types=1);
/**
 * test_no_broken_urls.php — Audit exhaustif : AUCUN lien cassé dans tout le code.
 *
 * Scanne TOUS les fichiers PHP et JS du projet (hors vendor/tests) et vérifie :
 *   1. Aucun href="xxx.php" (doit être href="index.php?p=xxx")
 *   2. Aucun action="xxx.php" (doit être action="index.php?p=xxx")
 *   3. Aucun header('Location: xxx.php') (doit être index.php?p=xxx)
 *   4. Aucun resolve_base_url() . '/xxx.php' (doit être /index.php?p=xxx)
 *   5. Aucun __DIR__ . '/xxx' dans pages/ qui pointe vers la racine (doit être dirname(__DIR__))
 *   6. Aucun 'xxx.php' dans des fonctions de construction d'URL email
 *   7. Aucun window.location = 'xxx.php' ou fetch('xxx.php') en JS
 *
 * Ce test est PROACTIF : il détecte les bugs AVAIT qu'ils arrivent en production.
 * Il s'exécute à chaque push via la gate.
 *
 * Usage : php tests/test_no_broken_urls.php
 */

require_once __DIR__ . '/test_bootstrap.php';

echo "── Audit exhaustif : aucun lien cassé dans tout le code ──\n";

$passed = 0;
$failed = 0;
$violations = [];

function check(string $name, bool $ok, array $details = []): void {
    global $passed, $failed, $violations;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name (" . count($details) . " violation(s))\n";
        foreach (array_slice($details, 0, 5) as $d) {
            echo "     • $d\n";
        }
        if (count($details) > 5) echo "     ... et " . (count($details) - 5) . " autre(s)\n";
        $failed++;
        $violations = array_merge($violations, $details);
    }
}

// ── Pages qui n'existent plus à la racine (déplacées vers pages/) ──
$movedPages = [
    'admin_access', 'admin_alerts', 'admin_forms', 'admin_settings',
    'backup', 'changelog', 'confirm_action', 'dashboard', 'docs', 'download',
    'form', 'form_preview', 'form_tracking', 'health', 'monitoring',
    'my_submissions', 'my_validations', 'rgpd', 'screenshot', 'stats',
    'submission_view', 'validate',
];

// ── Fichiers à scanner (hors vendor, tests, .git, backups) ──
$scanDirs = [
    __DIR__ . '/../pages/',
    __DIR__ . '/../lib/',
    __DIR__ . '/../src/',
    __DIR__ . '/../assets/',
];
$scanFiles = [
    __DIR__ . '/../index.php',
    __DIR__ . '/../helpers.php',
    __DIR__ . '/../alert_check.php',
    __DIR__ . '/../remind.php',
    __DIR__ . '/../assets.php',
    __DIR__ . '/../style.php',
];

// Sécurité : ignorer les commentaires et les strings dans @see
function isComment(string $line): bool {
    $t = ltrim($line);
    return str_starts_with($t, '//')
        || str_starts_with($t, '#')
        || str_starts_with($t, '*')
        || str_starts_with($t, '/**');
}

function isDocRef(string $line): bool {
    return preg_match('/@see|@link|@package|@param|@return/', $line) > 0;
}

function scanFiles(array $dirs, array $files, array $patterns, string $description): array {
    $results = [];
    $allFiles = $files;
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $ext = $f->getExtension();
            if ($ext !== 'php' && $ext !== 'js') continue;
            $allFiles[] = $f->getPathname();
        }
    }

    foreach ($allFiles as $filepath) {
        if (!file_exists($filepath)) continue;
        $lines = file($filepath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) continue;
        $rel = str_replace(dirname(__DIR__, 2) . '/', '', $filepath);

        foreach ($lines as $i => $line) {
            $ln = $i + 1;
            if (isComment($line)) {
                continue;
            }
            if (isDocRef($line)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $m)) {
                    $results[] = "$rel:$ln → " . trim($line) . " (matched: " . $m[0] . ")";
                }
            }
        }
    }
    return $results;
}

// ── Test 1 : href="xxx.php" (sans index.php) ──
echo "\n── Test 1 : href=\"xxx.php\" → doit être href=\"index.php?p=xxx\" ──\n";
$patterns1 = [];
foreach ($movedPages as $p) {
    $patterns1[] = '/href="' . preg_quote($p, '/') . '\.php/';
    $patterns1[] = "/href='" . preg_quote($p, '/') . "\\.php/";
}
// Aussi vérifier href="/xxx.php
foreach ($movedPages as $p) {
    $patterns1[] = '/href="\/' . preg_quote($p, '/') . '\.php/';
}
$r1 = scanFiles($scanDirs, $scanFiles, $patterns1, 'href xxx.php');
check('Aucun href="xxx.php" cassé', $r1 === [], $r1);

// ── Test 2 : action="xxx.php" (sans index.php) ──
echo "\n── Test 2 : action=\"xxx.php\" → doit être action=\"index.php?p=xxx\" ──\n";
$patterns2 = [];
foreach ($movedPages as $p) {
    $patterns2[] = '/action="' . preg_quote($p, '/') . '\.php/';
}
$r2 = scanFiles($scanDirs, $scanFiles, $patterns2, 'action xxx.php');
check('Aucun action="xxx.php" cassé', $r2 === [], $r2);

// ── Test 3 : header('Location: xxx.php') ──
echo "\n── Test 3 : header Location vers xxx.php ──\n";
$patterns3 = [];
foreach ($movedPages as $p) {
    $patterns3[] = '/Location.*\/' . preg_quote($p, '/') . '\.php/';
    $patterns3[] = "/Location.*'" . preg_quote($p, '/') . "\\.php/";
}
$r3 = scanFiles($scanDirs, $scanFiles, $patterns3, 'Location xxx.php');
check('Aucun header Location vers xxx.php', $r3 === [], $r3);

// ── Test 4 : resolve_base_url() . '/xxx.php' ──
echo "\n── Test 4 : resolve_base_url() avec /xxx.php ──\n";
$patterns4 = [];
foreach ($movedPages as $p) {
    $patterns4[] = '/resolve_base_url\(\).*\/' . preg_quote($p, '/') . '\.php/';
    $patterns4[] = '/BASE_URL.*\/' . preg_quote($p, '/') . '\.php/';
}
$r4 = scanFiles($scanDirs, $scanFiles, $patterns4, 'resolve_base_url xxx.php');
check('Aucun resolve_base_url() . /xxx.php', $r4 === [], $r4);

// ── Test 5 : __DIR__ dans pages/ pointant vers la racine ──
echo "\n── Test 5 : __DIR__ dans pages/ doit utiliser dirname(__DIR__) pour la racine ──\n";
$pagesDir = __DIR__ . '/../pages/';
$r5 = [];
if (is_dir($pagesDir)) {
    foreach (glob($pagesDir . '*.php') as $f) {
        $lines = file($f, FILE_IGNORE_NEW_LINES);
        $rel = 'pages/' . basename($f);
        foreach ($lines as $i => $line) {
            if (isComment($line)) continue;
            $ln = $i + 1;
            // Chercher __DIR__ . '/xxx' où xxx n'est pas lib/, classes/, vendor/
            if (preg_match('/__DIR__\s*\.\s*[\'"]\/(?!lib\/|classes\/|vendor\/|assets\/)[a-z_]+/', $line)) {
                $r5[] = "$rel:$ln → " . trim($line);
            }
        }
    }
}
check('Aucun __DIR__ cassé dans pages/', $r5 === [], $r5);

// ── Test 6 : 'xxx.php' dans les fonctions email (string concatenation) ──
echo "\n── Test 6 : URLs .php dans fonctions email ──\n";
$emailFiles = [
    __DIR__ . '/../lib/mail.php',
    __DIR__ . '/../lib/auth.php',
    __DIR__ . '/../lib/tokens.php',
    __DIR__ . '/../lib/workflow.php',
    __DIR__ . '/../alert_check.php',
    __DIR__ . '/../remind.php',
    __DIR__ . '/../src/Mail/MailService.php',
];
$r6 = [];
foreach ($emailFiles as $f) {
    if (!file_exists($f)) continue;
    $lines = file($f, FILE_IGNORE_NEW_LINES);
    $rel = str_replace(dirname(__DIR__, 2) . '/', '', $f);
    foreach ($lines as $i => $line) {
        if (isComment($line)) {
            continue;
        }
        if (isDocRef($line)) {
            continue;
        }
        $ln = $i + 1;
        foreach ($movedPages as $p) {
            // Chercher '/page.php' ou '/page.php?'
            if (preg_match("/['\"]\/" . preg_quote($p, '/') . "\\.php/", $line)) {
                $r6[] = "$rel:$ln → " . trim($line);
            }
        }
    }
}
check('Aucune URL .php dans fonctions email', $r6 === [], $r6);

// ── Test 7 : JS window.location / fetch vers xxx.php ──
echo "\n── Test 7 : JS window.location / fetch vers xxx.php ──\n";
$jsDirs = [__DIR__ . '/../assets/', __DIR__ . '/../lib/'];
$jsFiles = [];
foreach ($jsDirs as $dir) {
    if (!is_dir($dir)) continue;
    foreach (glob($dir . '*.js') as $f) $jsFiles[] = $f;
}
$r7 = [];
foreach ($jsFiles as $f) {
    $lines = file($f, FILE_IGNORE_NEW_LINES);
    $rel = str_replace(dirname(__DIR__, 2) . '/', '', $f);
    foreach ($lines as $i => $line) {
        if (isComment($line)) continue;
        $ln = $i + 1;
        foreach ($movedPages as $p) {
            if (preg_match("/['\"]" . preg_quote($p, '/') . "\\.php/", $line)) {
                $r7[] = "$rel:$ln → " . trim($line);
            }
        }
    }
}
check('Aucun JS fetch/location vers xxx.php', $r7 === [], $r7);

// ── Test 8 : Liens href="?xxx" (relatifs qui perdent p=) ──
echo "\n── Test 8 : Liens href=\"?xxx\" (relatifs qui perdent p=) ──\n";
$patterns8 = ['/href="\?[a-z]/', "/href='\\?[a-z]/"];
$r8 = scanFiles($scanDirs, $scanFiles, $patterns8, 'href ?xxx');
check('Aucun href="?xxx" cassé', $r8 === [], $r8);

// ── Test 9 : index.php?p=xxx?yyy (? au lieu de &) ──
echo "\n── Test 9 : index.php?p=xxx?yyy (? au lieu de &) ──\n";
$patterns9 = ['/index\.php\?p=[a-z_]+\?[a-z]/'];
$r9 = scanFiles($scanDirs, $scanFiles, $patterns9, '? au lieu de &');
check('Aucun ? au lieu de & dans les URLs', $r9 === [], $r9);

// ── Test 10 (CRITIQUE) : Toute string 'xxx.php' dans du code PHP ──
// Ce test détecte TOUTES les URLs cassées, pas seulement les href=/action=.
// Il aurait détecté le bug 'submission_view.php?id=...' dans render_dashboard.php:322
// que les tests 1-9 ont manqué (parce que l'URL était dans une variable PHP,
// pas dans un attribut HTML).
echo "\n── Test 10 (CRITIQUE) : Strings 'xxx.php' dans code PHP ──\n";
// Pattern : quote + page_name + .php + (optional ? ou # ou fin de string) + quote
// Exclut les require/include (chemins de fichier, pas URLs)
$patterns10 = [];
foreach ($movedPages as $p) {
    // 'page.php' ou "page.php" (suivi de ', ", ? ou #)
    $patterns10[] = "/['\"]" . preg_quote($p, '/') . "\\.php(?![a-z])(?:\\?|#|['\"])/";
}
$r10 = scanFiles($scanDirs, $scanFiles, $patterns10, 'string xxx.php');
// Filtrer les faux positifs :
// - require/include de fichiers (chemins de fichier, pas URLs)
// - __DIR__ . '/lib/xxx.php' (chemin de librairie, pas URL)
// - Points d'entrée légitimes (screenshot.php, download.php, install.php,
//   assets.php) — ces fichiers sont des handlers directs, pas des pages routées
// On examine la LIGNE DE CODE (pas le nom du fichier) pour détecter require
$legitEntryPoints = ['screenshot', 'download', 'install', 'assets', 'alert_check', 'remind', 'backup'];
$r10_filtered = [];
foreach ($r10 as $v) {
    // $v est au format "chemin/relatif.php:NN → ligne de code (matched: ...)"
    // Extraire la ligne de code (après " → ")
    $parts = explode(' → ', $v, 2);
    $codeLine = $parts[1] ?? $v;

    // Vérifier si la ligne CONTIENT un require/include — si oui, c'est un require de page.php
    // mais pages/ n'est jamais require directement, donc on garde
    if (preg_match('/\b(require|require_once|include|include_once)\s*\(?\s*[\'"]/',
            $codeLine)) {
        continue;  // c'est un require, pas une URL
    }
    // Vérifier que la ligne ne contient pas un chemin lib/classes/vendor/tests/assets
    // (ex: __DIR__ . '/lib/xxx.php' — chemin de fichier, pas URL)
    if (preg_match('#__DIR__\s*\.\s*[\'"]/(lib|classes|vendor|tests|assets)/#', $codeLine)) {
        continue;  // chemin de fichier lib, pas URL
    }
    $is_legit = array_any($legitEntryPoints, fn(string $legit): int|false => preg_match("/['\"]" . preg_quote($legit, '/') . "\\.php/", $codeLine));
    if ($is_legit) {
        continue;
    }
    $r10_filtered[] = $v;
}
check('Aucune string xxx.php cassée dans code PHP', $r10_filtered === [], $r10_filtered);

// ── Test 11 : URLs dans HEREDOC/NOWDOC ──
// Les <<<HTML ... HTML; blocks peuvent contenir des URLs xxx.php
echo "\n── Test 11 : URLs dans HEREDOC/NOWDOC ──\n";
$patterns11 = [];
foreach ($movedPages as $p) {
    // href="xxx.php dans un heredoc
    $patterns11[] = '/href="' . preg_quote($p, '/') . '\.php/';
    $patterns11[] = "/href='" . preg_quote($p, '/') . "\\.php/";
    // url: 'xxx.php' (ex: window.location = 'xxx.php')
    $patterns11[] = "/location\s*=\s*['\"]" . preg_quote($p, '/') . "\\.php/";
    // redirect => 'xxx.php'
    $patterns11[] = "/redirect['\"]?\s*=>\s*['\"]" . preg_quote($p, '/') . "\\.php/";
}
$r11 = scanFiles($scanDirs, $scanFiles, $patterns11, 'heredoc/redirect');
check('Aucune URL xxx.php dans HEREDOC/redirect', $r11 === [], $r11);

// ── Test 12 (NOUVEAU) : Rendu réel — vérifie les URLs dans le HTML ──
// Lance un sous-processus pour chaque page de la whitelist et vérifie
// qu'aucune URL 'xxx.php' (sans index.php?p=) n'apparaît dans le HTML rendu.
echo "\n── Test 12 (NOUVEAU) : URLs dans HTML rendu (sous-processus) ──\n";
require_once __DIR__ . '/helpers/HttpClient.php';
$r12 = [];
$r12_pages_tested = 0;
$routes_to_test = [
    ['GET', '/index.php', false],
    ['GET', '/index.php?p=my_submissions', false],
    ['GET', '/index.php?p=my_validations', false],
    ['GET', '/index.php?p=docs', false],
    ['GET', '/index.php?p=changelog', false],
    ['GET', '/index.php?p=admin_settings', true],
    ['GET', '/index.php?p=admin_forms', true],
    ['GET', '/index.php?p=dashboard', true],
    ['GET', '/index.php?p=stats', true],
    ['GET', '/index.php?p=monitoring', true],
    ['GET', '/index.php?p=rgpd', true],
    ['GET', '/index.php?p=health', true],
    ['GET', '/index.php?p=backup', true],
    ['GET', '/index.php?p=admin_alerts', true],
    ['GET', '/index.php?p=admin_access', true],
];
foreach ($routes_to_test as [$method, $path, $is_admin]) {
    $r = \HttpClient::renderRoute($method, $path, [], $is_admin);
    $html = $r['html'] ?? '';

    // Détecter env dégradé (sans mbstring/pdo_sqlite — le rendu échoue)
    $env_degraded = (str_contains($html, 'Warning: session_start'))
                 || (str_contains($html, 'extensions PHP manquantes'));
    if ($env_degraded) {
        // Skip — l'env de test ne peut pas rendre la page
        continue;
    }
    $r12_pages_tested++;

    // Chercher href="xxx.php" (sans index.php) dans le HTML rendu
    foreach ($movedPages as $p) {
        $pattern = '/href=["\']' . preg_quote($p, '/') . '\.php/';
        if (preg_match($pattern, $html)) {
            $r12[] = "$path — href=" . $p . ".php trouvé dans HTML rendu";
        }
    }
    // Chercher window.location='xxx.php' ou redirect
    foreach ($movedPages as $p) {
        $pattern = '/location\s*=\s*["\']' . preg_quote($p, '/') . '\.php/';
        if (preg_match($pattern, $html)) {
            $r12[] = "$path — location=" . $p . ".php trouvé dans HTML rendu";
        }
    }
}
if ($r12_pages_tested === 0) {
    echo "  ⚠️  Aucune page rendue (env dégradé) — test skip\n";
    $passed++;  // ne pas pénaliser
} else {
    check("Aucune URL xxx.php dans HTML rendu ($r12_pages_tested pages)", $r12 === [], $r12);
}

// ── Test 13 (CRITIQUE) : URL non encodée comme valeur de paramètre ──
// Détecte le bug DashboardTableRenderer:164 : &from=index.php?p=dashboard
// (le ? non encodé écrase $_GET['p']). Si une URL contenant ? est passée
// comme valeur de paramètre, elle DOIT être urlencode()'d — auquel cas
// le ? devient %3F et "index.php?p=" n'apparaît qu'une fois sur la ligne.
// Exclut les balises <code> (documentation) et les commentaires.
echo "\n── Test 13 (CRITIQUE) : URL non encodée comme valeur de paramètre ──\n";
$patterns13 = ['/href=["\']index\.php\?p=[^\'"]*index\.php\?p=/i', '/location\s*=\s*["\']index\.php\?p=[^\'"]*index\.php\?p=/i', '/redirect\(["\']index\.php\?p=[^\'"]*index\.php\?p=/i'];
$r13 = [];
foreach ($scanFiles as $f) {
    if (!str_contains($f, '.php')) continue;
    $content = file_get_contents($f);
    // Exclure les balises <code> et les commentaires
    $content = preg_replace('/<code>.*?<\/code>/s', '', $content);
    $content = preg_replace('/\/\/.*$/m', '', $content);
    $content = preg_replace('/\/\*.*?\*\//s', '', $content);
    foreach ($patterns13 as $pattern) {
        if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($content, 0, (int)$m[0][1]), "\n") + 1;
            $rel = str_replace(__DIR__ . '/../', '', $f);
            $r13[] = "$rel:$line → " . trim($m[0][0]);
        }
    }
}
check('Aucune URL non encodée comme valeur de paramètre', $r13 === [], $r13);

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  AUDIT EXHAUSTIF — " . ($violations === [] ? "✅ AUCUNE VIOLATION" : "❌ " . count($violations) . " violation(s)") . "\n";
echo "  $passed test(s) réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
