// test_e2e_full_flow.js — Test Playwright de smoke test (rendu HTML réel).
//
// LIMITES CONNUES :
//  - Ce test ne couvre pas les pages admin (monitoring.php, admin_settings.php)
//    car elles nécessitent une authentification via AUTH_USER (header IIS).
//    PHP -S ne remplit pas $_SERVER['AUTH_USER'] à partir d'un header HTTP,
//    et le router pré-processing n'est pas fiable dans cet environnement.
//    Les pages admin sont couvertes par test_all.php (section 4) qui vérifie
//    qu'elles se chargent sans erreur fatale en TEST_MODE.
//  - Ce test ne fait pas de submit POST complet avec Playwright car le
//    CSRF token est lié à la session PHP, et Playwright gère mal les
//    sessions avec PHP -S. Le submit POST est couvert par
//    tests/test_form_render_html.php qui utilise un sous-processus PHP
//    avec session pré-initialisée.
//
// Ce test couvre :
//  - Page d'accueil (index.php) se charge
//  - form.php?f=onboarding se charge avec formulaire, checkbox RGPD, submit
//
// Usage : node tests/test_e2e_full_flow.js

const { firefox } = require('playwright');
const { spawn } = require('child_process');
const http = require('http');
const path = require('path');
const fs = require('fs');

const PROJECT_ROOT = path.resolve(__dirname, '..');
const PORT = 8877;
const BASE_URL = `http://127.0.0.1:${PORT}`;

// Sauvegarder le proxy avant de le supprimer pour l'inner http.get
const _savedProxy = process.env.HTTP_PROXY || process.env.http_proxy || '';

// Supprimer le proxy pour les appels Node.js directs (health check)
// ET pour le serveur PHP spawné (sinon PHP tente de passer par le proxy)
delete process.env.HTTP_PROXY;
delete process.env.http_proxy;
delete process.env.HTTPS_PROXY;
delete process.env.https_proxy;

let phpServer = null;

// ─── Démarrer le serveur PHP intégré ───
function startPhpServer() {
    return new Promise((resolve, reject) => {
        // Tuer tout serveur existant sur le port (cross-platform)
        try {
            if (process.platform === 'win32') {
                const out = require('child_process').execSync('netstat -ano | findstr :' + PORT, { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
                const pids = [...new Set(out.match(/\s(\d+)\s*$/gm)?.map(m => m.trim()) || [])];
                pids.forEach(pid => { try { require('child_process').execSync('taskkill /F /PID ' + pid); } catch (_) {} });
            } else {
                require('child_process').execSync('pkill -f "php -S 127.0.0.1:' + PORT + '" 2>/dev/null');
            }
        } catch (_) {}

        // Démarrer avec TEST_MODE=false (pas de variable d'env)
        // On neutralise APP_TEST_MODE pour que core_bootstrap définisse TEST_MODE=false.
        // On utilise tests/router_test_auth.php qui convertit HTTP_AUTH_USER en AUTH_USER
        // (sinon PHP -S le met dans HTTP_AUTH_USER et AuthService ne le voit pas).
        phpServer = spawn('php', ['-S', `127.0.0.1:${PORT}`, 'tests/router_test_auth.php'], {
            cwd: PROJECT_ROOT,
            stdio: ['ignore', 'pipe', 'pipe'],
            detached: false,
            env: {
                ...process.env,
                APP_TEST_MODE: '',           // Désactive TEST_MODE
                APP_TEST_SECRET: '',
            },
        });

        phpServer.on('error', (err) => {
            console.error('PHP server error:', err);
            reject(err);
        });

        phpServer.stderr.on('data', (data) => {
            // Uncomment for debug : console.error('[PHP]', data.toString().trim());
        });

        // Attendre que le serveur soit prêt — attendre que le port soit ouvert
        let attempts = 0;
        const check = () => {
            attempts++;
            const net = require('net');
            const socket = new net.Socket();
            socket.setTimeout(1000);
            socket.on('connect', () => {
                // Port ouvert — serveur PHP prêt
                socket.destroy();
                resolve();
            });
            socket.on('error', () => {
                socket.destroy();
                if (attempts < 30) setTimeout(check, 300);
                else reject(new Error('Server not reachable after 30 attempts'));
            });
            socket.on('timeout', () => {
                socket.destroy();
                if (attempts < 30) setTimeout(check, 300);
                else reject(new Error('Server not reachable'));
            });
            socket.connect(PORT, '127.0.0.1');
        };
        setTimeout(check, 300);
    });
}

function stopPhpServer() {
    if (phpServer) {
        try {
            process.kill(-phpServer.pid);
        } catch (_) {}
        phpServer = null;
    }
}

// ─── Helpers de test ───
let passed = 0, failed = 0;
function ok(name)  { console.log(`  ✅ ${name}`); passed++; }
function ko(name, msg) { console.log(`  ❌ ${name}` + (msg ? ` — ${msg}` : '')); failed++; }

async function main() {
    console.log('── Démarrage du serveur PHP ──');
    await startPhpServer();
    console.log(`  Serveur prêt sur ${BASE_URL}\n`);

    // Lancer Firefox — bypass proxy système
    const browser = await firefox.launch({
        headless: true,
        proxy: _savedProxy ? { server: 'per-proxy', bypass: '127.0.0.1,localhost' } : undefined,
    });
    // Utiliser AUTH_USER pour simuler l'authentification IIS/Kerberos
    // sans activer TEST_MODE. L'admin "admin@ci.test"
    // est présent dans la table admins de la DB de test.
    const context = await browser.newContext({
        extraHTTPHeaders: {
            'AUTH_USER': 'DREETS\\admin',
        },
    });

    console.log('── Test 1 : Page d\'accueil se charge ──');
    const page1 = await context.newPage();
    try {
        const resp = await page1.goto(`${BASE_URL}/index.php`, { waitUntil: 'domcontentloaded', timeout: 10000 });
        if (resp && resp.status() === 200) {
            ok('index.php retourne 200');
        } else {
            ko('index.php retourne 200', `status: ${resp ? resp.status() : 'no response'}`);
        }
        const title = await page1.title();
        if (title && title.length > 0) {
            ok(`Titre de la page : "${title}"`);
        } else {
            ko('Titre de la page présent');
        }
    } catch (e) {
        ko('index.php se charge', e.message);
    }
    await page1.close();

    console.log('\n── Test 2 : form.php?f=onboarding se charge avec formulaire ──');
    const page2 = await context.newPage();
    try {
        // On doit passer un utilisateur — sinon get_auth_user() retourne '' et
        // form.php affiche une erreur. On tente sans headers spéciaux.
        const resp = await page2.goto(`${BASE_URL}/index.php?p=form&f=onboarding`, { waitUntil: 'domcontentloaded', timeout: 10000 });
        const html = await page2.content();
        if (html.includes('id="form-main"')) {
            ok('form.php contient <form id="form-main">');
        } else {
            ko('form.php contient <form id="form-main">', 'form tag absent');
        }
        if (html.includes('name="rgpd_consent"')) {
            ok('form.php contient la checkbox rgpd_consent');
        } else {
            ko('form.php contient la checkbox rgpd_consent');
        }
        if (html.includes('Envoyer ma demande')) {
            ok('form.php contient le bouton "Envoyer ma demande"');
        } else {
            ko('form.php contient le bouton "Envoyer ma demande"');
        }
    } catch (e) {
        ko('form.php se charge', e.message);
    }
    await page2.close();

    // ─── Résumé ───
    console.log('\n═══════════════════════════════════════════════════');
    console.log(`  RÉSULTATS Playwright : ${passed} réussi(s) / ${failed} échoué(s) / ${passed + failed} total`);
    console.log('═══════════════════════════════════════════════════');

    await browser.close();
    stopPhpServer();

    process.exit(failed > 0 ? 1 : 0);
}

main().catch((err) => {
    console.error('Erreur fatale:', err);
    stopPhpServer();
    process.exit(2);
});
