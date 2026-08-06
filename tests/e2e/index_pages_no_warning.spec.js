// index_pages_no_warning.spec.js — Vérifie que les pages servies via index.php
// sont exemptes de warning PHP dans le corps HTTP et dans stderr.
//
// Contexte (bug 2026-08-06) : certains contrôleurs peuvent émettre des
// warnings PHP silencieux (stat failed sur un fichier absent, clé inexistante
// dans un tableau, etc.) qui ne sont pas détectés par les tests unitaires
// (display_errors=Off en CI) mais qui pollueraient la réponse HTTP.
//
// Ce spec reproduit un parcours complet des pages principales avec auth admin
// et vérifie que chaque page retourne HTTP 200, un corps sans warning PHP,
// et stderr sans erreur PHP.
//
// Usage : node tests/e2e/index_pages_no_warning.spec.js
//         E2E_ADMIN_AUTH='DREETS\<login local admin>' node tests/e2e/index_pages_no_warning.spec.js (dev local)

const http = require('http');
const {
    TestRun,
    startTestServer,
    markStderr,
    capturePhpErrors,
    BASE_URL,
} = require('./helpers');

// AUTH_USER admin envoyé sur chaque requête (les pages admin exigent un
// admin en DB). Défaut 'DREETS\admin' → admin@ci.test (DB seedée en CI).
// En local, passer E2E_ADMIN_AUTH='DREETS\<login local admin en DB>'.
const ADMIN_AUTH = process.env.E2E_ADMIN_AUTH || 'DREETS\\admin';

// Format display_errors de PHP dans le corps : "<br /><b>Warning</b>: ..."
// Même pattern que assets_css_pure.spec.js
const PHP_ERROR_IN_BODY = /<\s*b\s*>(?:Warning|Notice|Deprecated|Fatal error|Parse error)<\s*\/b\s*>/i;

// GET simple sans navigateur (identique à assets_css_pure.spec.js), avec
// le header AUTH_USER (simulation IIS/Kerberos via router_test_auth.php).
function httpGet(url) {
    return new Promise((resolve, reject) => {
        http.get(url, { headers: { 'AUTH_USER': ADMIN_AUTH } }, (res) => {
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

/**
 * Vérifie qu'une page est servie sans warning PHP.
 * @param {TestRun} t
 * @param {string} url  URL complète (BASE_URL + path)
 * @param {string} label  Nom lisible de la page
 */
async function checkPage(t, url, label) {
    t.section(label);
    const marker = markStderr();
    const resp = await httpGet(url);

    // HTTP 200
    if (resp.status === 200) {
        t.ok(`${label} : HTTP 200`);
    } else {
        t.ko(`${label} : HTTP 200`, `status=${resp.status}`);
    }

    // Pas de warning PHP dans le corps
    if (PHP_ERROR_IN_BODY.test(resp.body)) {
        t.ko(`${label} : corps sans warning PHP`, `extrait : "${resp.body.substring(0, 200)}"`);
    } else {
        t.ok(`${label} : corps sans warning PHP`);
    }

    // Pas d'erreur PHP dans stderr
    await new Promise((r) => setTimeout(r, 50));
    const errs = capturePhpErrors(marker);
    if (errs.ok) {
        t.ok(`${label} : stderr sans erreur PHP`);
    } else {
        t.ko(`${label} : stderr sans erreur PHP`, errs.errors.join(' | ').substring(0, 200));
    }
}

async function main() {
    const t = new TestRun();
    let stop;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer());
        console.log('  Serveur prêt.\n');

        const pages = [
            { url: BASE_URL + '/',                label: 'Accueil (/)' },
            { url: BASE_URL + '/index.php?p=health', label: 'Health' },
            { url: BASE_URL + '/index.php?p=docs',   label: 'Documentation' },
            { url: BASE_URL + '/index.php?p=form&f=onboarding', label: 'Formulaire (onboarding)' },
            { url: BASE_URL + '/index.php?p=admin_settings', label: 'Admin — settings' },
            { url: BASE_URL + '/index.php?p=monitoring', label: 'Monitoring' },
            { url: BASE_URL + '/index.php?p=my_submissions', label: 'Mes soumissions' },
        ];

        for (const page of pages) {
            await checkPage(t, page.url, page.label);
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
