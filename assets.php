<?php
declare(strict_types=1);

/**
 * assets.php — Sert tous les assets (CSS compilé, JS) avec cache HTTP.
 *
 * Objectifs :
 *  1. Aucun asset online (CDN, Google Fonts, etc.) — tout est servi localement.
 *  2. Cache HTTP navigateur : ETag + Last-Modified + Cache-Control.
 *     → Si l'asset n'a pas changé, le navigateur reçoit un 304 Not Modified
 *       (0 byte transféré, ~0 ms).
 *  3. Compilation CSS à la volée : concatène les 8 fichiers lib/style_*.css
 *     en un seul blob (1 requête au lieu de 8).
 *
 * Usage :
 *   <link rel="stylesheet" href="assets.php?type=css">   → sert le CSS compilé
 *   <script src="assets.php?type=js&file=form-progress"> → sert un JS spécifique
 *
 * IMPORTANT : on NE charge PAS helpers.php immédiatement, car helpers.php
 * déclenche session_start() + send_security_headers() qui envoient des headers
 * HTTP (Content-Type: text/html par défaut). On veut contrôler le Content-Type
 * nous-mêmes (text/css ou application/javascript).
 *
 * On charge helpers.php uniquement pour get_latest_version() (cache-busting)
 * et après avoir envoyé nos propres headers.
 */

// Désactiver le mimetype par défaut (text/html) pour pouvoir setter le nôtre
ini_set('default_mimetype', '');
ini_set('default_charset', 'UTF-8');

// ── Charger l'autoload PSR-4 pour pouvoir utiliser les enums ──
// (on ne charge PAS helpers.php ici — on veut éviter session_start()
// pour les assets statiques ; on charge seulement l'autoloader)
require_once __DIR__ . '/vendor/autoload.php';

use App\Enum\AssetType;

// ── Sécurité : empêcher l'accès direct aux fichiers PHP sensibles ──
$file = $_GET['file'] ?? '';

// Valider le type via l'enum
$type = AssetType::tryFrom($_GET['type'] ?? '');
if ($type === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Type invalide. Utilisez ?type=css ou ?type=js&file=<name>';
    exit;
}

// ── Pour le JS, on n'a pas besoin de helpers.php — servir directement ──
// (évite le coût de session_start() + send_security_headers() pour un fichier statique)
if ($type === AssetType::Js) {
    // Valider le nom du fichier (sécurité : pas de path traversal)
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $file) !== 1) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Nom de fichier invalide. Utilisez ?type=js&file=form-progress';
        exit;
    }

    $jsFile = __DIR__ . '/assets/' . $file . '.js';
    if (!is_file($jsFile)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "JS file not found: $file.js";
        exit;
    }

    $mtime = filemtime($jsFile);
    $hash = md5_file($jsFile);
    // Version extraite du CHANGELOG.md sans charger helpers.php (légèrement redondant
    // mais évite le coût de session_start pour un asset statique)
    $version = extract_version_from_changelog(__DIR__ . '/CHANGELOG.md');
    $etag = $hash . '-v' . $version;

    if (send_cache_headers_and_check_304($etag, (int) $mtime)) {
        exit;  // 304 Not Modified
    }

    header('Content-Type: application/javascript; charset=UTF-8', true);
    header('X-Content-Type-Options: nosniff');
    readfile($jsFile);
    exit;
}

// ── Pour le CSS, on a besoin de la liste des sections ──
// Pas besoin de helpers.php non plus — on lit les fichiers directement.
// $type est forcément 'css' ici (le cas 'js' a déjà été traité + exit ci-dessus,
// et les types invalides ont été rejetés par in_array plus haut).
else {
    $sections = ['tokens', 'layout', 'components', 'forms', 'responsive', 'features', 'onboarding', 'pages', 'utility'];
    // CSS spécifiques à certaines pages (anciennement inline via $page_css)
    // TOUS les fichiers *_page.css sont inclus ici — sinon les pages perdent
    // leur CSS spécifique (bug introduit quand $page_css a été deprecated).
    $pageCssFiles = [
        'submission_view_page', 'monitoring_page', 'admin_settings_page',
        'dashboard_page', 'index_page', 'admin_forms_page', 'backup_page',
    ];
    $cssDir = __DIR__ . '/lib';

    // Calculer le hash + Last-Modified des fichiers CSS
    $hashes = [];
    $maxMtime = 0;
    foreach ($sections as $section) {
        $cssFile = $cssDir . '/style_' . $section . '.css';
        if (!is_file($cssFile)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "CSS file missing: style_$section.css";
            exit;
        }
        $content = file_get_contents($cssFile);
        $hashes[] = $section . ':' . md5((string) $content);
        $mtime = filemtime($cssFile);
        if ($mtime !== false && $mtime > $maxMtime) $maxMtime = $mtime;
    }
    // Ajouter les CSS spécifiques de pages (non bloquants si absents)
    foreach ($pageCssFiles as $pcf) {
        $cssFile = $cssDir . '/' . $pcf . '.css';
        if (is_file($cssFile)) {
            $content = file_get_contents($cssFile);
            $hashes[] = $pcf . ':' . md5((string) $content);
            $mtime = filemtime($cssFile);
            if ($mtime !== false && $mtime > $maxMtime) $maxMtime = $mtime;
        }
    }

    $version = extract_version_from_changelog(__DIR__ . '/CHANGELOG.md');
    $etag = md5(implode('|', $hashes)) . '-v' . $version;

    if (send_cache_headers_and_check_304($etag, $maxMtime)) {
        exit;  // 304 Not Modified
    }

    header('Content-Type: text/css; charset=UTF-8', true);
    header('X-Content-Type-Options: nosniff');

    // Cache disque : si on a déjà compilé pour cette version, servir le fichier
    $cacheDir = __DIR__ . '/db/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/assets_css_v' . $version . '.css';

    // filemtime() émet un Warning si le fichier n'existe pas (1er hit après
    // déploiement) — vérifier is_file() AVANT, sinon le warning pollue le CSS.
    $cacheMtime = is_file($cacheFile) ? filemtime($cacheFile) : false;
    if ($cacheMtime !== false && $cacheMtime >= $maxMtime) {
        readfile($cacheFile);
    } else {
        $compiled = '';
        foreach ($sections as $section) {
            $compiled .= file_get_contents($cssDir . '/style_' . $section . '.css') . "\n";
        }
        // Inclure les CSS spécifiques de pages
        foreach ($pageCssFiles as $pcf) {
            $cssFile = $cssDir . '/' . $pcf . '.css';
            if (is_file($cssFile)) {
                $compiled .= file_get_contents($cssFile) . "\n";
            }
        }
        @file_put_contents($cacheFile, $compiled);
        echo $compiled;
    }
    exit;
}

// ── Helper : extraire la version depuis CHANGELOG.md sans charger helpers.php ──
function extract_version_from_changelog(string $path): string {
    if (!is_file($path)) return '0.0.0';
    $content = file_get_contents($path);
    if ($content === false) return '0.0.0';
    // Chercher la 1re ligne "## [X.Y.Z]" 
    if (preg_match('/^##\s*\[(\d+\.\d+\.\d+)\]/m', $content, $m) === 1) {
        return $m[1];
    }
    return '0.0.0';
}

// ── Fonction helper : envoyer les headers de cache + gérer le 304 ──
function send_cache_headers_and_check_304(string $etag, int $lastModified): bool {
    header('ETag: "' . $etag . '"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('Cache-Control: public, max-age=86400, must-revalidate');  // 24h
    header('Vary: Accept-Encoding');

    // Vérifier If-None-Match (ETag)
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch !== '') {
        $etags = array_map('trim', explode(',', $ifNoneMatch));
        foreach ($etags as $clientEtag) {
            $clientEtag = preg_replace('/^W\//', '', $clientEtag) ?? $clientEtag;
            $clientEtag = trim($clientEtag, '"');
            if ($clientEtag === $etag) {
                http_response_code(304);
                return true;
            }
        }
    }

    // Vérifier If-Modified-Since (Last-Modified)
    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifModifiedSince !== '') {
        $clientTs = strtotime($ifModifiedSince);
        if ($clientTs !== false && $clientTs >= $lastModified) {
            http_response_code(304);
            return true;
        }
    }

    return false;
}
