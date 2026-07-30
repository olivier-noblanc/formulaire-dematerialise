// admin_pages.spec.js — Test e2e des pages admin (chargement correct).
//
// Pour chaque page admin :
//   1. GET /index.php?p=admin_settings, /index.php?p=monitoring, /index.php?p=admin_forms,
//      /index.php?p=admin_access, /index.php?p=admin_alerts, /index.php?p=dashboard, /index.php?p=stats
//   2. Vérifier status 200
//   3. Vérifier pas de warning PHP dans stderr du serveur
//      (Warning / Notice / Deprecated / Fatal error / Parse error)
//   4. Vérifier que le HTML contient un <title> non vide
//
// Note : on simule l'auth admin via AUTH_USER: DREETS\admin.local
// (admin.local@exemple.invalid est présent dans la table admins).
//
// Note sur la détection des warnings PHP : la spec mentionnait `page.on('console')`
// mais le browser console ne capte PAS les erreurs PHP — elles vont dans stderr
// du process PHP -S. On utilise donc capturePhpErrors() du helper qui scanne
// le stderr du serveur PHP.
//
// Usage : node tests/e2e/admin_pages.spec.js

const {
    TestRun,
    startTestServer,
    launchBrowser,
    newContext,
    getPageHtml,
    markStderr,
    capturePhpErrors,
} = require('./helpers');

// ─── Pages admin à tester ────────────────────────────────────────
// Toutes ces pages appellent require_admin() (sauf admin_access.php qui est
// la page où l'utilisateur demande l'accès admin). On utilise le header
// AUTH_USER= DREETS\admin.local (admin en DB).
const ADMIN_PAGES = [
    { url: '/index.php?p=admin_settings', label: 'admin_settings.php (config SMTP/sécurité)' },
    { url: '/index.php?p=monitoring',     label: 'monitoring.php (tableau de bord monitoring)' },
    { url: '/index.php?p=admin_forms',    label: 'admin_forms.php (gestion formulaires/étapes)' },
    { url: '/index.php?p=admin_access',   label: 'admin_access.php (demande accès admin)' },
    { url: '/index.php?p=admin_alerts',   label: 'admin_alerts.php (règles d\'alerte)' },
    { url: '/index.php?p=dashboard',      label: 'dashboard.php (dashboard admin)' },
    { url: '/index.php?p=stats',          label: 'stats.php (statistiques)' },
];

async function main() {
    const t = new TestRun();
    let stop, browser;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer('DREETS\\admin.local'));
        console.log('  Serveur prêt.\n');

        browser = await launchBrowser();
        const context = await newContext(browser, 'DREETS\\admin.local');

        t.section('Tests pages admin — chargement + titre + pas d\'erreur PHP');

        for (const p of ADMIN_PAGES) {
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

                // 2. <title> non vide
                const titleMatch = html.match(/<title>([^<]+)<\/title>/i);
                if (titleMatch && titleMatch[1].trim().length > 0) {
                    t.ok(`[${p.url}] <title> non vide ("${titleMatch[1].trim().substring(0, 60)}")`);
                } else {
                    t.ko(`[${p.url}] <title> non vide`, 'balise title absente ou vide');
                }

                // 3. Pas d'erreur PHP dans stderr du serveur
                //    (l'erreur peut mettre ~50ms à arriver, on attend un peu)
                await new Promise((r) => setTimeout(r, 80));
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

        // ═══════════════════════════════════════════════════════════════
        // Test supplémentaire : vérifier qu'on a bien accès au contenu admin
        // (vs. page "Accès refusé" si require_admin() échoue)
        // ═══════════════════════════════════════════════════════════════
        t.section('Vérification de l\'auth admin (pas page "Accès refusé")');

        // Recharger monitoring.php et vérifier qu'on n'est pas sur une 403
        const checkPage = await context.newPage();
        try {
            const { html, status } = await getPageHtml(checkPage, '/index.php?p=monitoring');
            if (status === 200 && !html.includes('Accès refusé') && !html.includes('Vous devez être administrateur')) {
                t.ok('Accès admin accordé (pas de page "Accès refusé")');
            } else {
                t.ko(
                    'Accès admin accordé (pas de page "Accès refusé")',
                    `status=${status}, page "Accès refusé" détectée`
                );
            }
        } finally {
            await checkPage.close().catch(() => {});
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
