// assets_css_pure.spec.js — Vérifie que assets.php sert du CSS pur, sans
// warning PHP dans le corps HTTP ni dans stderr, y compris sur cache froid.
//
// Contexte (bug 2026-08-06) : assets.php appelait filemtime() AVANT de vérifier
// is_file() → au 1er hit après déploiement (fichier cache absent), PHP émettait
// "Warning: filemtime(): stat failed" dans le corps HTTP, corrompant le CSS.
// Les tests existants ne le détectaient pas car :
//   1. test_assets_cache.php ne vérifiait que status + headers, jamais le corps
//   2. le scénario cache froid n'était pas garanti (cache jamais purgé)
//   3. display_errors=Off en CI masquait le warning dans le corps
// Ce spec rend le scénario déterministe (purge du cache avant le 1er hit) et
// vérifie le corps à la fois sur cache froid et cache chaud.
//
// Usage : node tests/e2e/assets_css_pure.spec.js

const http = require('http');
const fs = require('fs');
const path = require('path');
const {
    TestRun,
    startTestServer,
    markStderr,
    capturePhpErrors,
    PROJECT_ROOT,
    BASE_URL,
} = require('./helpers');

// Format display_errors de PHP dans le corps : "<br /><b>Warning</b>: ..."
// Le </b> est OBLIGATOIRE dans le pattern — le format réel est
// "<b>Warning</b>:  message" (testé contre le bug réel 2026-08-06).
const PHP_ERROR_IN_BODY = /<\s*b\s*>(?:Warning|Notice|Deprecated|Fatal error|Parse error)<\s*\/b\s*>/i;

// GET simple sans navigateur (le serveur PHP -S tourne déjà avec le router).
function httpGet(url) {
    return new Promise((resolve, reject) => {
        http.get(url, (res) => {
            const chunks = [];
            res.on('data', (c) => chunks.push(c));
            res.on('end', () => resolve({
                status: res.statusCode,
                headers: res.headers,
                body: Buffer.concat(chunks).toString('utf8'),
            }));
        }).on('error', reject);
    });
}

// Purge les fichiers cache CSS pour reproduire le scénario "1er hit après
// déploiement" (cache froid → assets.php recompile).
function purgeCssCache() {
    const cacheDir = path.join(PROJECT_ROOT, 'db', 'cache');
    if (!fs.existsSync(cacheDir)) return;
    for (const f of fs.readdirSync(cacheDir)) {
        if (f.startsWith('assets_css_v') && f.endsWith('.css')) {
            fs.unlinkSync(path.join(cacheDir, f));
        }
    }
}

async function main() {
    const t = new TestRun();
    let stop;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer());
        console.log('  Serveur prêt.\n');

        // ── Cache froid : le chemin du bug (recompilation) ──
        t.section('assets.php — cache froid (1er hit après déploiement)');
        purgeCssCache();
        const marker = markStderr();
        const resp = await httpGet(BASE_URL + '/assets.php?type=css');

        if (resp.status === 200) {
            t.ok('assets.php?type=css retourne HTTP 200');
        } else {
            t.ko('assets.php?type=css retourne HTTP 200', `status=${resp.status}`);
        }

        const hasCssContentType = /text\/css/i.test(resp.headers['content-type'] || '');
        if (hasCssContentType) {
            t.ok('Content-Type: text/css');
        } else {
            t.ko('Content-Type: text/css', `content-type=${resp.headers['content-type']}`);
        }

        if (resp.body.length > 0) {
            t.ok(`Corps non vide (${resp.body.length} octets)`);
        } else {
            t.ko('Corps non vide', 'body vide');
        }

        // Le CSS compilé commence par un commentaire /* ... */ — un warning PHP
        // affiché en amont casserait ce préfixe.
        if (resp.body.trim().startsWith('/*')) {
            t.ok('Le corps commence par un commentaire CSS /*');
        } else {
            t.ko('Le corps commence par un commentaire CSS /*', `début : "${resp.body.substring(0, 120)}"`);
        }

        if (PHP_ERROR_IN_BODY.test(resp.body)) {
            t.ko('Aucun warning PHP dans le corps CSS', `extrait : "${resp.body.substring(0, 200)}"`);
        } else {
            t.ok('Aucun warning PHP dans le corps CSS');
        }

        // Filet indépendant de display_errors : le warning filemtime part aussi
        // dans stderr du serveur (log_errors) même quand le corps n'est pas pollué.
        await new Promise((r) => setTimeout(r, 50));
        const errs = capturePhpErrors(marker);
        if (errs.ok) {
            t.ok('Aucune erreur PHP dans stderr du serveur');
        } else {
            t.ko('Aucune erreur PHP dans stderr du serveur', errs.errors.join(' | ').substring(0, 200));
        }

        // ── Cache chaud : le fichier compilé est servi sans recompilation ──
        t.section('assets.php — cache chaud (2e hit, sert le fichier compilé)');
        const marker2 = markStderr();
        const resp2 = await httpGet(BASE_URL + '/assets.php?type=css');

        if (resp2.status === 200) {
            t.ok('2e requête retourne HTTP 200');
        } else {
            t.ko('2e requête retourne HTTP 200', `status=${resp2.status}`);
        }

        if (resp2.body.trim().startsWith('/*') && !PHP_ERROR_IN_BODY.test(resp2.body)) {
            t.ok('2e requête : corps CSS pur (/* ... */, aucun warning)');
        } else {
            t.ko('2e requête : corps CSS pur', `extrait : "${resp2.body.substring(0, 200)}"`);
        }

        await new Promise((r) => setTimeout(r, 50));
        const errs2 = capturePhpErrors(marker2);
        if (errs2.ok) {
            t.ok('2e requête : aucune erreur PHP dans stderr');
        } else {
            t.ko('2e requête : aucune erreur PHP dans stderr', errs2.errors.join(' | ').substring(0, 200));
        }

        console.log(t.summary());
    } catch (err) {
        t.ko('Erreur fatale', err.message);
        console.log(t.summary());
    } finally {
        if (stop) await stop();
    }
    t.exit();
}

main();
