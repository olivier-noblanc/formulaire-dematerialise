// smoke.spec.js — Test e2e smoke minimal (pages publiques se chargent).
//
// Pour chaque page publique :
//   1. GET /index.php?p=index, /index.php?p=health, /index.php?p=docs, /index.php?p=changelog, /index.php?p=form&f=onboarding
//   2. Status HTTP 200
//   3. <title> non vide
//   4. Aucune erreur PHP dans stderr du serveur (Warning/Notice/Deprecated/Fatal)
//
// Note : on simule l'auth via AUTH_USER pour que form.php?f=onboarding
// affiche bien le formulaire (sinon submitted_by='' et le rendu peut différer).
//
// Usage : node tests/e2e/smoke.spec.js

const {
    TestRun,
    startTestServer,
    launchBrowser,
    newContext,
    getPageHtml,
    markStderr,
    capturePhpErrors,
} = require('./helpers');

// ─── Pages à fumer ───────────────────────────────────────────────
const PAGES = [
    { url: '/',              label: 'index.php (page d\'accueil)' },
    { url: '/index.php?p=health',    label: 'health.php (healthcheck)' },
    { url: '/index.php?p=docs',      label: 'docs.php (documentation)' },
    { url: '/index.php?p=changelog', label: 'changelog.php (journal des versions)' },
    { url: '/index.php?p=form&f=onboarding', label: 'form.php?f=onboarding' },
];

async function main() {
    const t = new TestRun();
    let stop, browser;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer());
        console.log('  Serveur prêt.\n');

        browser = await launchBrowser();
        // Auth simulée — la plupart des pages publiques l'acceptent et l'ignorent
        const context = await newContext(browser, 'DREETS\\admin');

        t.section('Tests smoke — pages publiques');
        for (const p of PAGES) {
            const page = await context.newPage();
            const marker = markStderr();
            try {
                const { html, status } = await getPageHtml(page, p.url, { timeout: 15000 });

                // 1. Status 200
                if (status === 200) {
                    t.ok(`[${p.url}] HTTP 200`);
                } else {
                    t.ko(`[${p.url}] HTTP 200`, `status=${status}`);
                }

                // 2. <title> non vide — extraction via regex (la page est du HTML)
                const titleMatch = html.match(/<title>([^<]+)<\/title>/i);
                if (titleMatch && titleMatch[1].trim().length > 0) {
                    t.ok(`[${p.url}] <title> non vide ("${titleMatch[1].trim().substring(0, 60)}")`);
                } else {
                    t.ko(`[${p.url}] <title> non vide`, 'balise title absente ou vide');
                }

                // 3. Pas d'erreur PHP dans stderr
                //    On laisse un petit délai pour que le buffer stderr se flush
                await new Promise((r) => setTimeout(r, 50));
                const errs = capturePhpErrors(marker);
                if (errs.ok) {
                    t.ok(`[${p.url}] Aucune erreur PHP dans stderr`);
                } else {
                    t.ko(
                        `[${p.url}] Aucune erreur PHP dans stderr`,
                        errs.errors.join(' | ').substring(0, 200)
                    );
                }
            } catch (e) {
                t.ko(`[${p.url}] chargement de la page`, e.message);
            } finally {
                await page.close().catch(() => {});
            }
        }

        console.log(t.summary());
    } catch (err) {
        t.ko('Erreur fatale', err.message);
        console.log(t.summary());
    } finally {
        if (browser) await browser.close().catch(() => {});
        if (stop) await stop();
    }
    t.exit();
}

main();
