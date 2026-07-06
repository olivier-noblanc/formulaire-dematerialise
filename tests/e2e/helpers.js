// helpers.js — Module réutilisable par tous les tests e2e Playwright.
//
// Fournit :
//  - TestRun            : compteur ok/ko + résumé final + exit code
//  - startTestServer()  : démarre un serveur PHP -S avec router_test_auth.php
//                         (qui simule AUTH_USER IIS via un header HTTP) et
//                         retourne une fonction stop() asynchrone
//  - launchBrowser()    : raccourci chromium.launch headless
//  - newContext()       : nouveau context Playwright avec header AUTH_USER pré-injecté
//  - getCsrfToken()     : GET une URL et extrait le csrf_token du <input hidden>
//  - getPageHtml()      : GET une URL et retourne page.content()
//  - assertContains()   : assertion de présence d'une substring dans le HTML
//  - assertNotContains() : assertion d'absence
//  - sleep()            : promesse de pause
//  - capturePhpErrors() : vérifie qu'aucune erreur PHP n'a été émise sur stderr
//
// Convention : pas de framework de test. Chaque spec importe ce module,
// instancie TestRun, exécute ses tests via page.goto/page.fill/page.click,
// appelle t.ok()/t.ko() pour tracker les résultats, puis t.summary()+t.exit().
//
// Compatible Node 18+ — async/await pur, pas de top-level await.

const { chromium } = require('playwright');
const { spawn, execSync } = require('child_process');
const http = require('http');
const path = require('path');

const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const PORT = 8900;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const ROUTER_PATH = path.resolve(__dirname, '..', 'router_test_auth.php');

// ─── TestRun : compteur de résultats ──────────────────────────────
class TestRun {
    constructor() {
        this.passed = 0;
        this.failed = 0;
        this.failures = [];
    }
    ok(name) {
        console.log(`  ✅ ${name}`);
        this.passed++;
    }
    ko(name, msg) {
        const line = `  ❌ ${name}` + (msg ? ` — ${msg}` : '');
        console.log(line);
        this.failures.push({ name, msg });
        this.failed++;
    }
    section(title) {
        console.log(`\n── ${title} ──`);
    }
    summary() {
        const total = this.passed + this.failed;
        const lines = [
            '',
            '═══════════════════════════════════════════════════',
            `  RÉSULTATS : ${this.passed} réussi(s) / ${this.failed} échoué(s) / ${total} total`,
            '═══════════════════════════════════════════════════',
        ];
        if (this.failed > 0) {
            lines.push('  Échecs :');
            for (const f of this.failures) {
                lines.push(`    • ${f.name}${f.msg ? ' — ' + f.msg : ''}`);
            }
            lines.push('═══════════════════════════════════════════════════');
        }
        return lines.join('\n');
    }
    exit() {
        process.exit(this.failed > 0 ? 1 : 0);
    }
}

// ─── Gestion du serveur PHP -S ───────────────────────────────────
//
// Variables globales au module : un seul serveur à la fois par processus.
// Le stderr du serveur PHP est capturé dans un buffer pour détecter les
// warnings/notices/fatal errors émis pendant les tests.
let phpServer = null;
let stderrBuffer = '';
let stderrMarker = 0;

/**
 * Tue tout serveur PHP existant sur le port configuré.
 * Appelé systématiquement au démarrage pour éviter les conflits de port
 * (un test précédent qui aurait planté sans killer son serveur).
 */
function killExistingServer() {
    try {
        execSync(`pkill -f "php -S 127.0.0.1:${PORT}" 2>/dev/null || true`);
    } catch (_) {
        // pkill retourne 1 si aucun process trouvé → on ignore
    }
}

/**
 * Attend que le serveur PHP -S réponde 200 sur /index.php?p=health.
 * @param {number} maxAttempts  Nombre max de tentatives (1 toutes les 200ms)
 * @returns {Promise<void>}
 */
function waitForServer(maxAttempts = 50) {
    return new Promise((resolve, reject) => {
        let attempts = 0;
        const check = () => {
            attempts++;
            const req = http.get(`${BASE_URL}/index.php?p=health`, (res) => {
                // Consommer le body pour libérer le socket
                res.resume();
                if (res.statusCode === 200) {
                    resolve();
                } else if (attempts < maxAttempts) {
                    setTimeout(check, 200);
                } else {
                    reject(new Error(`Serveur non prêt après ${attempts} tentatives (status ${res.statusCode})`));
                }
            });
            req.on('error', () => {
                if (attempts < maxAttempts) setTimeout(check, 200);
                else reject(new Error('Serveur injoignable'));
            });
            req.setTimeout(2000, () => {
                req.destroy();
                if (attempts < maxAttempts) setTimeout(check, 200);
                else reject(new Error('Timeout serveur'));
            });
        };
        // Petit délai initial pour laisser le temps au process de démarrer
        setTimeout(check, 300);
    });
}

/**
 * Démarre un serveur PHP -S avec tests/router_test_auth.php qui convertit
 * le header HTTP AUTH_USER en variable serveur AUTH_USER (simule IIS/Kerberos
 * sans TEST_MODE). Retourne une fonction stop() asynchrone.
 *
 * @param {string} userHeader  Valeur du header AUTH_USER (ex. 'DREETS\\olivier.noblanc')
 *                             Le header sera injecté dans le context Playwright via newContext()
 * @returns {Promise<{stop: () => Promise<void>}>}
 */
async function startTestServer(userHeader = 'DREETS\\olivier.noblanc') {
    if (phpServer !== null) {
        throw new Error('Un serveur PHP tourne déjà dans ce processus — appeler stop() d\'abord');
    }
    killExistingServer();
    // Réinitialiser le buffer stderr et le marker
    stderrBuffer = '';
    stderrMarker = 0;

    phpServer = spawn('php', ['-S', `127.0.0.1:${PORT}`, ROUTER_PATH], {
        cwd: PROJECT_ROOT,
        stdio: ['ignore', 'pipe', 'pipe'],
        // detached:true pour pouvoir tuer tout le groupe de processus (PHP -S
        // peut spawn des enfants pour les uploads lourds).
        detached: true,
        env: {
            ...process.env,
            // Neutraliser TEST_MODE : on veut tester le vrai code path de prod
            // (CSRF actif, session réelle, etc.).
            APP_TEST_MODE: '',
            APP_TEST_SECRET: '',
        },
    });

    // Capturer stderr pour détecter les warnings/notices/fatal errors PHP
    phpServer.stderr.on('data', (data) => {
        stderrBuffer += data.toString();
    });

    // Si le serveur crash au démarrage, on log l'erreur pour débug
    phpServer.on('exit', (code, signal) => {
        // Log non bloquant — utile pour débugger
        if (code !== null && code !== 0 && code !== 15 && signal !== 'SIGTERM' && signal !== 'SIGKILL') {
            console.error(`[PHP-S] Process exited with code ${code} signal ${signal}`);
        }
    });

    phpServer.on('error', (err) => {
        console.error('[PHP-S] Spawn error:', err.message);
    });

    // Attendre que le serveur réponde
    await waitForServer();

    // stop() — kill propre via SIGTERM puis SIGKILL en fallback
    const stop = async () => {
        if (phpServer === null) return;
        try {
            // Tuer tout le groupe ( detached:true → -pid = groupe )
            process.kill(-phpServer.pid, 'SIGTERM');
        } catch (_) {
            try { process.kill(phpServer.pid, 'SIGTERM'); } catch (_) {}
        }
        // Laisser 200ms pour que le process se termine proprement
        await new Promise((r) => setTimeout(r, 200));
        try {
            process.kill(-phpServer.pid, 'SIGKILL');
        } catch (_) {
            try { process.kill(phpServer.pid, 'SIGKILL'); } catch (_) {}
        }
        phpServer = null;
    };

    return { stop };
}

// ─── Capture des erreurs PHP dans stderr ──────────────────────────

/**
 * Marque la position courante du buffer stderr. À appeler AVANT un page.goto()
 * pour pouvoir mesurer les nouvelles lignes émises par cette requête.
 * @returns {number}  Position (longueur du buffer) à utiliser avec capturePhpErrors()
 */
function markStderr() {
    stderrMarker = stderrBuffer.length;
    return stderrMarker;
}

/**
 * Vérifie qu'aucune erreur PHP (Warning/Notice/Deprecated/Fatal error/Parse error)
 * n'a été émise sur stderr depuis le marker donné.
 *
 * @param {number} marker  Position retournée par markStderr()
 * @param {string} label   Label pour le message d'erreur
 * @returns {{ok: boolean, errors: string[]}}
 */
function capturePhpErrors(marker, label = '') {
    const since = stderrBuffer.slice(marker);
    // Patterns typiques des erreurs PHP dans stderr (CLI / -S)
    // Ex : "PHP Warning:  ..." / "PHP Notice:  ..." / "PHP Fatal error:  ..."
    //      "PHP Stack trace:" / "PHP Parse error:"
    const errorLines = since
        .split('\n')
        .filter((l) => /^\s*PHP (Warning|Notice|Deprecated|Fatal error|Parse error):/i.test(l));
    return {
        ok: errorLines.length === 0,
        errors: errorLines,
    };
}

// ─── Helpers Playwright ──────────────────────────────────────────

/**
 * Lance Chromium headless.
 * @returns {Promise<import('playwright').Browser>}
 */
async function launchBrowser() {
    return chromium.launch({ headless: true });
}

/**
 * Crée un nouveau context Playwright avec le header AUTH_USER pré-injecté
 * (simule l'authentification IIS/Kerberos).
 *
 * @param {import('playwright').Browser} browser
 * @param {string} userHeader  Valeur du header AUTH_USER
 * @returns {Promise<import('playwright').BrowserContext>}
 */
async function newContext(browser, userHeader = 'DREETS\\olivier.noblanc') {
    return browser.newContext({
        extraHTTPHeaders: {
            AUTH_USER: userHeader,
        },
    });
}

/**
 * GET une URL et retourne le HTML de la page (page.content()).
 * Lance page.goto avec waitUntil 'domcontentloaded'.
 *
 * @param {import('playwright').Page} page
 * @param {string} url  URL relative (ex: '/form.php?f=onboarding') OU absolue
 * @param {object} opts Options passées à page.goto (timeout par défaut 15s)
 * @returns {Promise<{html: string, status: number|null, resp: import('playwright').Response|null}>}
 */
async function getPageHtml(page, url, opts = {}) {
    const fullUrl = url.startsWith('http') ? url : BASE_URL + url;
    const resp = await page.goto(fullUrl, {
        waitUntil: 'domcontentloaded',
        timeout: 15000,
        ...opts,
    });
    const html = await page.content();
    const status = resp ? resp.status() : null;
    return { html, status, resp };
}

/**
 * Récupère un CSRF token depuis une page HTML donnée.
 * Lit la valeur de l'<input type="hidden" name="csrf_token">.
 *
 * @param {import('playwright').Page} page  Page déjà chargée (avec un form)
 * @returns {Promise<string>}  Le token CSRF (ou '' si absent)
 */
async function getCsrfToken(page) {
    try {
        const token = await page.locator('input[name="csrf_token"]').first().inputValue();
        return token || '';
    } catch (_) {
        return '';
    }
}

// ─── Helpers d'assertion ─────────────────────────────────────────

/**
 * Assert que html contient needle. Track le résultat dans le TestRun.
 * @param {string} html
 * @param {string} needle
 * @param {string} testName
 * @param {TestRun} t
 */
function assertContains(html, needle, testName, t) {
    if (html.includes(needle)) {
        t.ok(testName);
    } else {
        t.ko(testName, `substring absente : "${needle}"`);
    }
}

/**
 * Assert que html NE contient PAS needle. Track le résultat dans le TestRun.
 * @param {string} html
 * @param {string} needle
 * @param {string} testName
 * @param {TestRun} t
 */
function assertNotContains(html, needle, testName, t) {
    if (!html.includes(needle)) {
        t.ok(testName);
    } else {
        t.ko(testName, `substring présente alors qu'elle ne devrait pas : "${needle}"`);
    }
}

// ─── Divers ──────────────────────────────────────────────────────

/** @param {number} ms */
function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
}

module.exports = {
    // Constantes
    PROJECT_ROOT,
    PORT,
    BASE_URL,
    ROUTER_PATH,
    // Classe
    TestRun,
    // Serveur PHP
    startTestServer,
    killExistingServer,
    markStderr,
    capturePhpErrors,
    // Playwright
    launchBrowser,
    newContext,
    getPageHtml,
    getCsrfToken,
    // Assertions
    assertContains,
    assertNotContains,
    // Divers
    sleep,
};
