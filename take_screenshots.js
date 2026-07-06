const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'http://localhost:8899';
const SCREENSHOT_DIR = '/home/z/my-project/formulaire-dematerialise/docs/screenshots';

const PAGES = [
    { path: '/index.php', file: '01_index_agent.png', title: 'Accueil agent' },
    { path: '/index.php', file: '02_index_admin.png', title: 'Accueil admin' },
    { path: '/form.php?f=onboarding', file: '03_form_onboarding.png', title: 'Formulaire onboarding' },
    { path: '/form.php?f=acces_si', file: '04_form_acces_si.png', title: 'Formulaire accès SI' },
    { path: '/my_submissions.php', file: '05_my_submissions.png', title: 'Mes demandes' },
    { path: '/my_validations.php', file: '06_my_validations.png', title: 'Mes validations' },
    { path: '/dashboard.php', file: '07_dashboard.png', title: 'Supervision' },
    { path: '/monitoring.php', file: '08_monitoring.png', title: 'Surveillance' },
    { path: '/admin_access.php', file: '09_admin_access.png', title: 'Accès admin' },
    { path: '/admin_forms.php', file: '10_admin_forms.png', title: 'Gestion formulaires' },
    { path: '/admin_alerts.php', file: '11_admin_alerts.png', title: 'Alertes' },
    { path: '/admin_settings.php', file: '12_admin_settings.png', title: 'Paramètres' },
    { path: '/docs.php', file: '13_docs.png', title: 'Documentation' },
    { path: '/changelog.php', file: '14_changelog.png', title: 'Changelog' },
    { path: '/health.php', file: '15_health.png', title: 'Santé système' },
    { path: '/backup.php', file: '16_backup.png', title: 'Sauvegarde' },
    { path: '/rgpd.php', file: '17_rgpd.png', title: 'RGPD' },
    { path: '/stats.php', file: '18_stats.png', title: 'Statistiques' },
];

async function runScreenshots() {
    // Ensure directory exists
    if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    }

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        extraHTTPHeaders: {
            'X-Test-Mode': '1',
            'X-Test-User': 'test.agent@dreets.gouv.fr'
        }
    });

    let ok = 0;
    let fail = 0;
    const errors = [];

    for (const page of PAGES) {
        const p = await context.newPage();
        try {
            const response = await p.goto(`${BASE_URL}${page.path}`, {
                waitUntil: 'networkidle',
                timeout: 15000
            });

            const status = response ? response.status() : 0;

            // Check if response is JSON (test mode for some pages)
            const contentType = response ? response.headers()['content-type'] || '' : '';
            if (contentType.includes('application/json')) {
                console.log(`  ⏭️  ${page.file} — ${page.title} (JSON en mode test, ignoré)`);
                await p.close();
                continue;
            }

            if (status !== 200) {
                throw new Error(`HTTP ${status}`);
            }

            // Wait a bit for rendering
            await p.waitForTimeout(500);

            const screenshotPath = path.join(SCREENSHOT_DIR, page.file);
            await p.screenshot({ path: screenshotPath, fullPage: false });

            const fileSize = fs.statSync(screenshotPath).size;
            console.log(`  ✅ ${page.file} — ${page.title} (${(fileSize / 1024).toFixed(0)} Ko)`);
            ok++;

        } catch (err) {
            console.log(`  ❌ ${page.file} — ${page.title}: ${err.message}`);
            fail++;
            errors.push(`${page.file}: ${err.message}`);
        } finally {
            await p.close();
        }
    }

    await browser.close();

    console.log(`\n═══════════════════════════════════════════════════════════`);
    console.log(`  SCREENSHOTS: ${ok} OK / ${fail} échoué(s) / ${ok + fail} total`);
    console.log(`═══════════════════════════════════════════════════════════`);
    if (errors.length > 0) {
        console.log(`\nÉchecs:`);
        errors.forEach(e => console.log(`  • ${e}`));
    }

    process.exit(fail > 0 ? 1 : 0);
}

runScreenshots().catch(err => {
    console.error('Error:', err);
    process.exit(1);
});
