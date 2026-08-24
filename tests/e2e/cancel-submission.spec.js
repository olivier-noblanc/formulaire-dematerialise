// cancel-submission.spec.js — Test e2e du flux d'annulation de soumission
// depuis le dashboard admin.
//
// Contexte fonctionnel : sur le dashboard, chaque soumission au statut
// "En cours" porte un lien d'annulation ("Annuler") à l'intérieur d'un bloc
// <details> (submission_detail.php). Ce lien pointe vers la page de
// confirmation confirm_action (ConfirmActionController / ConfirmActionRenderer),
// avec un paramètre from= qui encode la page de retour ('index.php?p=dashboard'
// → 'index.php%3Fp%3Ddashboard', le '?' devient '%3F', l'URL complète tient
// dans un seul paramètre). Le bouton retour "Annuler" de cette page nous
// ramène au dashboard (href = from).
//
// Le lien n'existant que pour les soumissions "en_cours", ce test crée une
// soumission seed dédiée (seed_cancel_submission.php) au nom unique pour
// repérer le bon <details> sans collision avec les exécutions précédentes.
//
// Étapes :
//   1. Démarrer serveur PHP avec AUTH_USER: DREETS\admin (admin)
//   2. Seeder une soumission "en_cours" au nom unique
//   3. GET /index.php?p=dashboard (login admin simulé via header AUTH_USER)
//   4. Ouvrir le <details> de la soumission seed et récupérer le lien "Annuler"
//   5. Vérifier que son href encode correctement from= ('?' → '%3F', un seul param)
//   6. Cliquer "Annuler" → la page de confirmation s'affiche (HTTP 200, pas 404)
//   7. Cliquer le retour "Annuler" de la page de confirmation
//   8. Vérifier qu'on revient au dashboard
//
// Note : la soumission seed n'est pas nettoyée (workflow.db réel, cohérent
// avec full_submission_flow.spec.js — chaque exécution en ajoute une).
//
// Usage : node tests/e2e/cancel-submission.spec.js

const { execSync } = require('child_process');
const path = require('path');

const {
    TestRun,
    startTestServer,
    launchBrowser,
    newContext,
    getPageHtml,
    assertContains,
} = require('./helpers');

async function main() {
    const t = new TestRun();
    let stop, browser;
    const unique = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    const nom = 'TestCancel' + unique;
    const email = 'cancel-' + unique + '@test.local';

    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer('DREETS\\admin'));
        console.log('  Serveur prêt.\n');

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 2 : Seeder une soumission "en_cours" au nom unique
        // ═══════════════════════════════════════════════════════════════
        t.section('Seeder une soumission "en_cours" au nom unique');
        let uuid = '';
        try {
            uuid = execSync(
                'php ' + path.join(__dirname, 'seed_cancel_submission.php') + ' "' + nom + '" "' + email + '"',
                { encoding: 'utf8' }
            ).trim();
            if (/^[0-9a-f-]{36}$/i.test(uuid)) {
                t.ok(`Soumission seed créée (uuid=${uuid}, nom=${nom})`);
            } else {
                t.ko('Soumission seed créée', `sortie invalide du seed : "${uuid}"`);
            }
        } catch (e) {
            t.ko('Soumission seed créée', e.message);
        }

        browser = await launchBrowser();
        const context = await newContext(browser, 'DREETS\\admin');
        const page = await context.newPage();

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 3 : GET /index.php?p=dashboard (login admin simulé)
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 1 — Chargement du dashboard admin');
        const { html: dashHtml, status: dashStatus } = await getPageHtml(page, '/index.php?p=dashboard');

        if (dashStatus === 200) {
            t.ok('GET /index.php?p=dashboard → HTTP 200');
        } else {
            t.ko('GET /index.php?p=dashboard → HTTP 200', `status=${dashStatus}`);
        }
        assertContains(dashHtml, 'Tableau de bord', 'Page dashboard affichée', t);

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 4-5 : Ouvrir le <details> de la soumission seed et
        //             vérifier l'encodage de from= sur le lien "Annuler"
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 2 — Localisation du lien "Annuler" et encodage de from=');

        // Le <details> contenant notre soumission seed (repéré par son nom)
        const details = page.locator('details', { hasText: nom });

        let cancelHref = '';
        try {
            // Ouvrir le <details> pour rendre le lien visible et cliquable
            await details.locator('summary').click();
            // Le lien d'annulation est <a class="btn btn-danger">… Annuler</a>
            const cancelLink = details.locator('a.btn-danger:has-text("Annuler")').first();
            await cancelLink.waitFor({ state: 'visible', timeout: 8000 });
            cancelHref = (await cancelLink.getAttribute('href')) || '';
            t.ok(`Lien "Annuler" trouvé et visible pour la soumission seed`);
        } catch (e) {
            t.ko('Lien "Annuler" trouvé et visible', e.message);
        }

        if (cancelHref !== '') {
            // Vérifications structurelles de l'URL
            if (cancelHref.includes('p=confirm_action')) {
                t.ok('Lien pointe vers la page de confirmation (p=confirm_action)');
            } else {
                t.ko('Lien pointe vers la page de confirmation (p=confirm_action)', `href=${cancelHref}`);
            }
            if (cancelHref.includes('action=cancel_submission')) {
                t.ok('Lien porte action=cancel_submission');
            } else {
                t.ko('Lien porte action=cancel_submission', `href=${cancelHref}`);
            }
            if (/submission_id=[0-9a-f-]{36}(&|$)/i.test(cancelHref)) {
                t.ok('Lien porte un submission_id uuid valide');
            } else {
                t.ko('Lien porte un submission_id uuid valide', `href=${cancelHref}`);
            }

            // ── Encodage de from= : le '?' doit être '%3F', l'URL complète
            //    dans un seul paramètre (pas de '?' ni de '&' bruts dedans) ──
            const fromMatch = cancelHref.match(/from=([^&]*)/);
            const fromValue = fromMatch ? fromMatch[1] : '';
            if (fromValue === 'index.php%3Fp%3Ddashboard') {
                t.ok('from= correctement encodé (index.php%3Fp%3Ddashboard, \'?\' → \'%3F\')');
            } else {
                t.ko('from= correctement encodé', `fromValue="${fromValue}" (attendu "index.php%3Fp%3Ddashboard")`);
            }
            if (cancelHref.includes('?p=dashboard')) {
                t.ko('from= tient dans un seul paramètre', 'un \'?\' brut présent dans l\'URL (from non encodé, ou paramètre non final)');
            } else {
                t.ok('from= tient dans un seul paramètre (aucun \'?\' brut)');
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 6 : Cliquer "Annuler" → page de confirmation (pas de 404)
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 3 — Page de confirmation après clique sur "Annuler"');
        let confirmStatus = null;
        let confirmOk = false;
        try {
            const confirmLink = details.locator('a.btn-danger:has-text("Annuler")').first();
            // Capturer la réponse de navigation pour récupérer le HTTP status
            const confirmRespPromise = page.waitForResponse(
                (r) => r.url().includes('p=confirm_action'),
                { timeout: 15000 }
            );
            await Promise.all([
                page.waitForLoadState('domcontentloaded', { timeout: 15000 }),
                confirmLink.click(),
            ]);
            const confirmResp = await confirmRespPromise;
            confirmStatus = confirmResp ? confirmResp.status() : null;
            await page.waitForURL(/p=confirm_action/, { timeout: 15000 });
            t.ok('Navigation vers la page de confirmation effectuée');
            confirmOk = true;
        } catch (e) {
            t.ko('Navigation vers la page de confirmation effectuée', e.message);
        }

        let confirmHtml = '';
        if (confirmOk) {
            confirmHtml = await page.content();

            assertContains(confirmHtml, 'Annuler une soumission', 'Confirmation : titre "Annuler une soumission" présent', t);
            assertContains(
                confirmHtml,
                'Voulez-vous vraiment annuler la soumission',
                'Confirmation : message de confirmation présent',
                t
            );
            if (confirmStatus === 200) {
                t.ok('Page de confirmation servie (HTTP 200, pas de 404)');
            } else {
                t.ko('Page de confirmation servie (HTTP 200, pas de 404)', `status=${confirmStatus}`);
            }
        } else {
            t.ko('Page de confirmation servie (HTTP 200, pas de 404)', 'navigation échouée');
        }

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 7-8 : Cliquer le retour "Annuler" → retour au dashboard
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 4 — Retour au dashboard via le bouton "Annuler"');
        try {
            // Le bouton retour de la confirmation est <a class="btn btn-secondary">Annuler</a>
            const backLink = page.locator('a.btn-secondary:has-text("Annuler")').first();
            await backLink.waitFor({ state: 'visible', timeout: 8000 });
            t.ok('Bouton retour "Annuler" présent sur la page de confirmation');

            await Promise.all([
                page.waitForLoadState('domcontentloaded', { timeout: 15000 }),
                backLink.click(),
            ]);
            await page.waitForURL(/p=dashboard/, { timeout: 15000 });

            const returnedHtml = await page.content();
            assertContains(returnedHtml, 'Tableau de bord', 'On est revenu au dashboard (après annulation de l\'opération)', t);
        } catch (e) {
            t.ko('Retour au dashboard via le bouton "Annuler"', e.message);
        }

        console.log(t.summary());
    } catch (err) {
        t.ko('Erreur fatale', err.message + '\n' + err.stack);
        console.log(t.summary());
    } finally {
        if (browser) await browser.close().catch(() => {});
        if (stop) await stop();
    }
    t.exit();
}

main();
