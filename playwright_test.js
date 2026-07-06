const { chromium } = require('playwright');

const BASE_URL = 'http://localhost:8899';
const PAGES = [
    { path: '/health.php', title: 'Santé', expectNav: true },
    { path: '/index.php', title: 'Accueil', expectNav: true },
    { path: '/changelog.php', title: 'Journal', expectNav: true },
    { path: '/docs.php', title: 'Documentation', expectNav: true },
    { path: '/dashboard.php', title: 'Supervision', expectNav: true },
    { path: '/admin_forms.php', title: 'formulaires', expectNav: true },
    { path: '/admin_settings.php', title: 'Paramètres', expectNav: true },
    { path: '/admin_alerts.php', title: 'Alertes', expectNav: true },
    { path: '/monitoring.php', title: 'Surveillance', expectNav: true },
    { path: '/my_submissions.php', title: 'demandes', expectNav: true },
    { path: '/my_validations.php', title: 'validations', expectNav: true },
    { path: '/backup.php', title: 'Sauvegarde', expectNav: true },
    { path: '/rgpd.php', title: 'RGPD', expectNav: true },
    { path: '/stats.php', title: 'Statistiques', expectNav: true },
    { path: '/admin_access.php', title: 'Accès', expectNav: true },
    // validate.php without a token returns JSON in test mode (expected)
    { path: '/validate.php', title: 'Validation', expectNav: false, expectJson: true },
];

async function runTests() {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        extraHTTPHeaders: {
            'X-Test-Mode': '1',
            'X-Test-User': 'test.agent@dreets.gouv.fr'
        }
    });

    let passed = 0;
    let failed = 0;
    const errors = [];

    for (const page of PAGES) {
        const p = await context.newPage();
        try {
            const response = await p.goto(`${BASE_URL}${page.path}`, { 
                waitUntil: 'domcontentloaded',
                timeout: 10000 
            });

            const status = response ? response.status() : 0;
            const pageTitle = await p.title();

            // Check 1: HTTP status
            if (status !== 200) {
                throw new Error(`HTTP ${status}`);
            }

            // Special case: pages that return JSON in test mode
            if (page.expectJson) {
                const bodyText = await p.evaluate(() => document.body.innerText);
                try {
                    const json = JSON.parse(bodyText);
                    if (json._test_mode) {
                        console.log(`  ✅ ${page.path} — HTTP ${status}, JSON test mode (expected)`);
                        passed++;
                        continue;
                    }
                } catch (e) { /* not JSON, fall through to normal checks */ }
            }

            // Check 2: Page has proper HTML structure
            const hasDoctype = await p.evaluate(() => document.documentElement !== null);
            if (!hasDoctype) {
                throw new Error('No DOCTYPE/html element');
            }

            // Check 3: Page has <head> and <body>
            const hasHead = await p.evaluate(() => !!document.head);
            const hasBody = await p.evaluate(() => !!document.body);
            if (!hasHead || !hasBody) {
                throw new Error(`Missing head/body: head=${hasHead}, body=${hasBody}`);
            }

            // Check 4: Has nav (sidebar) if expected
            if (page.expectNav) {
                const hasNav = await p.evaluate(() => {
                    const nav = document.querySelector('nav');
                    return nav !== null;
                });
                if (!hasNav) {
                    throw new Error('Missing nav element');
                }
            }

            // Check 5: Has footer
            const hasFooter = await p.evaluate(() => {
                const footer = document.querySelector('footer');
                return footer !== null;
            });
            if (!hasFooter) {
                throw new Error('Missing footer');
            }

            // Check 6: Has main content
            const hasMain = await p.evaluate(() => {
                const main = document.querySelector('main#main-content');
                return main !== null;
            });
            if (!hasMain) {
                throw new Error('Missing main#main-content');
            }

            // Check 7: Has style (design system loaded)
            const hasStyle = await p.evaluate(() => {
                const styles = document.querySelectorAll('style');
                return styles.length > 0;
            });
            if (!hasStyle) {
                throw new Error('No style tags found');
            }

            // Check 8: No PHP errors visible
            const bodyText = await p.evaluate(() => document.body.innerText);
            if (bodyText.includes('Fatal error') || bodyText.includes('Parse error') || bodyText.includes('Warning:')) {
                throw new Error('PHP error visible on page');
            }

            console.log(`  ✅ ${page.path} — HTTP ${status}, title: "${pageTitle}"`);
            passed++;

        } catch (err) {
            console.log(`  ❌ ${page.path} — ${err.message}`);
            failed++;
            errors.push(`${page.path}: ${err.message}`);
        } finally {
            await p.close();
        }
    }

    await browser.close();

    console.log(`\n═══════════════════════════════════════════════════════════`);
    console.log(`  PLAYWRIGHT: ${passed} passed / ${failed} failed / ${passed + failed} total`);
    console.log(`═══════════════════════════════════════════════════════════`);
    if (errors.length > 0) {
        console.log(`\nFailed pages:`);
        errors.forEach(e => console.log(`  • ${e}`));
    }

    process.exit(failed > 0 ? 1 : 0);
}

runTests().catch(err => {
    console.error('Playwright error:', err);
    process.exit(1);
});
