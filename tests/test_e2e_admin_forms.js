// test_e2e_admin_forms.js — Test Playwright (MS Edge) de la page admin
// "Gestion des formulaires" (index.php?p=admin_forms).
//
// Régressions couvertes :
//  - Refactor 828a54f : les sections steps / champs / owners étaient vides
//    ("Aucune étape définie", "Aucun champ défini", "Aucun propriétaire défini")
//    car AdminFormsController ne chargeait plus les données.
//  - Les steps affichent leurs destinataires (objets {id, email}).
//
// Prérequis (DB locale db/workflow.db) :
//  - Formulaire "Accueil agent" (90c7b338-2dd1-422c-a2ac-06b5a02ed6e5) :
//    4 steps, 4 step_recipients, 22 form_fields, 2 form_owners.
//  - Admin "testeur@e2e.test" dans la table admins.
//
// Usage : node tests/test_e2e_admin_forms.js

const { chromium } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');

const PROJECT_ROOT = path.resolve(__dirname, '..');
const PORT = 8878;
const BASE_URL = `http://127.0.0.1:${PORT}`;
const FORM_ID = '90c7b338-2dd1-422c-a2ac-06b5a02ed6e5';

// Sauvegarder le proxy avant de le supprimer (inner http.get + serveur PHP)
const _savedProxy = process.env.HTTP_PROXY || process.env.http_proxy || '';
delete process.env.HTTP_PROXY;
delete process.env.http_proxy;
delete process.env.HTTPS_PROXY;
delete process.env.https_proxy;

let phpServer = null;

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

        phpServer = spawn('php', ['-S', `127.0.0.1:${PORT}`, 'tests/router_test_auth.php'], {
            cwd: PROJECT_ROOT,
            stdio: ['ignore', 'pipe', 'pipe'],
            detached: false,
            env: { ...process.env, APP_TEST_MODE: '', APP_TEST_SECRET: '' },
        });

        phpServer.on('error', (err) => { console.error('PHP server error:', err); reject(err); });
        phpServer.stderr.on('data', () => {}); // Uncomment pour debug : console.error('[PHP]', data.toString().trim());

        let attempts = 0;
        const check = () => {
            attempts++;
            const net = require('net');
            const socket = new net.Socket();
            socket.setTimeout(1000);
            socket.on('connect', () => { socket.destroy(); resolve(); });
            socket.on('error', () => { socket.destroy(); if (attempts < 30) setTimeout(check, 300); else reject(new Error('Server not reachable after 30 attempts')); });
            socket.on('timeout', () => { socket.destroy(); if (attempts < 30) setTimeout(check, 300); else reject(new Error('Server not reachable')); });
            socket.connect(PORT, '127.0.0.1');
        };
        setTimeout(check, 300);
    });
}

function stopPhpServer() {
    if (phpServer) { try { process.kill(-phpServer.pid); } catch (_) {} phpServer = null; }
}

// ─── Helpers de test ───
let passed = 0, failed = 0;
function ok(name)  { console.log(`  ✅ ${name}`); passed++; }
function ko(name, msg) { console.log(`  ❌ ${name}` + (msg ? ` — ${msg}` : '')); failed++; }

async function main() {
    console.log('── Démarrage du serveur PHP ──');
    await startPhpServer();
    console.log(`  Serveur prêt sur ${BASE_URL}\n`);

    // MS Edge installé (channel msedge) — pas de binaire à télécharger.
    const browser = await chromium.launch({
        channel: 'msedge',
        headless: true,
        proxy: _savedProxy ? { server: 'per-proxy', bypass: '127.0.0.1,localhost' } : undefined,
    });
    // L'admin "testeur@e2e.test" est dans la table admins de db/workflow.db.
    // router_test_auth.php convertit HTTP_AUTH_USER → AUTH_USER pour PHP -S.
    const context = await browser.newContext({
        extraHTTPHeaders: { 'AUTH_USER': 'testeur@e2e.test' },
    });

    console.log('── Test 1 : Page Gestion des formulaires (sélecteur) ──');
    const page1 = await context.newPage();
    try {
        const resp = await page1.goto(`${BASE_URL}/index.php?p=admin_forms`, { waitUntil: 'domcontentloaded', timeout: 10000 });
        if (resp && resp.status() === 200) ok('admin_forms retourne 200');
        else ko('admin_forms retourne 200', `status: ${resp ? resp.status() : 'no response'}`);
        const title = await page1.title();
        if (title.includes('Gestion des formulaires')) ok(`Titre : "${title}"`);
        else ko('Titre "Gestion des formulaires"', `titre: ${title}`);
    } catch (e) { ko('Page admin_forms se charge', e.message); }
    await page1.close();

    console.log('\n── Test 2 : Formulaire "Accueil agent" — sections steps/champs/owners ──');
    const page = await context.newPage();
    try {
        const resp = await page.goto(`${BASE_URL}/index.php?p=admin_forms&form_id=${FORM_ID}`, { waitUntil: 'domcontentloaded', timeout: 10000 });
        if (resp && resp.status() === 200) ok('admin_forms?form_id=... retourne 200');
        else ko('admin_forms?form_id=... retourne 200', `status: ${resp ? resp.status() : 'no response'}`);

        const html = await page.content();
        if (html.includes('Accueil agent')) ok('Le formulaire "Accueil agent" est sélectionné');
        else ko('Le formulaire "Accueil agent" est sélectionné');

        // Régressions 828a54f : plus aucun message "vide" pour les 3 sections
        if (!html.includes('Aucune étape définie')) ok('Section steps : pas de "Aucune étape définie"');
        else ko('Section steps : pas de "Aucune étape définie"');
        if (!html.includes('Aucun champ défini')) ok('Section champs : pas de "Aucun champ défini"');
        else ko('Section champs : pas de "Aucun champ défini"');
        if (!html.includes('Aucun propriétaire défini')) ok('Section owners : pas de "Aucun propriétaire défini"');
        else ko('Section owners : pas de "Aucun propriétaire défini"');

        // Présence réelle des données
        const stepCards = await page.locator('.step-card').count();
        if (stepCards === 4) ok(`4 steps affichés (${stepCards})`);
        else ko('4 steps affichés', `count: ${stepCards}`);
        const recipientChips = await page.locator('.recipient-chip').count();
        if (recipientChips >= 1) ok(`Destinataires visibles (${recipientChips} chips)`);
        else ko('Destinataires visibles', `count: ${recipientChips}`);
        const fieldRows = await page.locator('tr[id^="field-"]').count();
        if (fieldRows >= 1) ok(`Champs affichés (${fieldRows} lignes)`);
        else ko('Champs affichés', `count: ${fieldRows}`);
        const ownerRows = await page.locator('#owners tbody tr').count();
        if (ownerRows >= 1) ok(`Owners affichés (${ownerRows} lignes)`);
        else ko('Owners affichés', `count: ${ownerRows}`);
    } catch (e) { ko('Page formulaire sélectionné', e.message); }
    await page.close();

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
