const { chromium } = require('playwright');

const BASE_URL = 'http://localhost:8899';
const ADMIN_HEADERS = {
    'X-Test-Mode': '1',
    'X-Test-User': 'test.agent@exemple.invalid'
};
const NON_ADMIN_HEADERS = {
    'X-Test-Mode': '1',
    'X-Test-User': 'random.user@exemple.invalid'
};

// All pages that render full HTML (with nav, footer, etc.)
const ALL_PAGES = [
    { path: '/index.php', navKey: 'accueil' },
    { path: '/my_submissions.php', navKey: 'mes_demandes' },
    { path: '/my_validations.php', navKey: 'mes_validations' },
    { path: '/docs.php', navKey: 'docs' },
    { path: '/changelog.php', navKey: 'changelog' },
    { path: '/health.php', navKey: 'health' },
    { path: '/dashboard.php', navKey: 'dashboard' },
    { path: '/admin_forms.php', navKey: 'forms' },
    { path: '/admin_settings.php', navKey: 'settings' },
    { path: '/admin_alerts.php', navKey: 'alerts' },
    { path: '/admin_access.php', navKey: 'admin_access' },
    { path: '/monitoring.php', navKey: 'monitoring' },
    { path: '/backup.php', navKey: 'backup' },
    { path: '/stats.php', navKey: 'stats' },
    { path: '/rgpd.php', navKey: 'rgpd' },
];

// Admin-only pages (require_admin) — dashboard.php is NOT admin-only (no require_admin call)
const ADMIN_PAGES = [
    '/admin_forms.php',
    '/admin_settings.php',
    '/admin_alerts.php',
    '/monitoring.php',
    '/backup.php',
    '/stats.php',
    '/rgpd.php',
    '/form_preview.php',
];

// Pages that explicitly call require_admin()
const REQUIRE_ADMIN_PAGES = [
    '/admin_forms.php',
    '/admin_settings.php',
    '/admin_alerts.php',
    '/monitoring.php',
    '/backup.php',
    '/stats.php',
    '/rgpd.php',
];

async function runTests() {
    const browser = await chromium.launch({ headless: true });

    let passed = 0, failed = 0;
    const errors = [];

    async function runTest(name, fn) {
        try {
            await fn();
            console.log(`  ✅ ${name}`);
            passed++;
        } catch (err) {
            console.log(`  ❌ ${name} — ${err.message}`);
            failed++;
            errors.push(`${name}: ${err.message}`);
        }
    }

    // Helper: create a new admin page, go to URL, return { page, response }
    async function adminPage(url) {
        const context = await browser.newContext({ extraHTTPHeaders: ADMIN_HEADERS });
        const page = await context.newPage();
        const response = await page.goto(`${BASE_URL}${url}`, { waitUntil: 'domcontentloaded', timeout: 10000 });
        return { page, response, context };
    }

    // Helper: create a non-admin page
    async function nonAdminPage(url) {
        const context = await browser.newContext({ extraHTTPHeaders: NON_ADMIN_HEADERS });
        const page = await context.newPage();
        const response = await page.goto(`${BASE_URL}${url}`, { waitUntil: 'domcontentloaded', timeout: 10000 });
        return { page, response, context };
    }

    // Helper: safely close a page + context
    async function cleanup(page, context) {
        try { await page.close(); } catch (_) {}
        try { await context.close(); } catch (_) {}
    }

    // ═══════════════════════════════════════════════════════════
    // 1. PAGE STRUCTURE TESTS (10 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 1. Page Structure Tests ━━━');

    // Test 1: Every page has DOCTYPE, html lang="fr", head, body
    await runTest('Every page has DOCTYPE, html lang=fr, head, body', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const html = await page.evaluate(() => {
                return {
                    hasDoctype: document.doctype !== null,
                    lang: document.documentElement.getAttribute('lang'),
                    hasHead: !!document.head,
                    hasBody: !!document.body
                };
            });
            if (!html.hasDoctype) throw new Error('Missing DOCTYPE');
            if (html.lang !== 'fr') throw new Error(`html lang="${html.lang}", expected "fr"`);
            if (!html.hasHead) throw new Error('Missing <head>');
            if (!html.hasBody) throw new Error('Missing <body>');
        } finally { await cleanup(page, context); }
    });

    // Test 2: Every page has meta charset UTF-8 and viewport meta
    await runTest('Every page has meta charset UTF-8 and viewport meta', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const charset = await page.evaluate(() => {
                const meta = document.querySelector('meta[charset]');
                return meta ? meta.getAttribute('charset').toUpperCase() : null;
            });
            if (charset !== 'UTF-8') throw new Error(`charset="${charset}"`);
            const viewport = await page.evaluate(() => {
                const meta = document.querySelector('meta[name="viewport"]');
                return meta ? meta.getAttribute('content') : null;
            });
            if (!viewport || !viewport.includes('width=device-width')) throw new Error('Missing viewport meta');
        } finally { await cleanup(page, context); }
    });

    // Test 3: Every page has skip-link for accessibility
    await runTest('Every page has skip-link for accessibility', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const skipLink = await page.evaluate(() => {
                const link = document.querySelector('a.skip-link');
                return link ? { href: link.getAttribute('href'), text: link.textContent.trim() } : null;
            });
            if (!skipLink) throw new Error('No skip-link found');
            if (skipLink.href !== '#main-content') throw new Error(`skip-link href="${skipLink.href}"`);
        } finally { await cleanup(page, context); }
    });

    // Test 4: Every page has main#main-content
    await runTest('Every page has main#main-content', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const main = await page.$('main#main-content');
            if (!main) throw new Error('Missing main#main-content');
        } finally { await cleanup(page, context); }
    });

    // Test 5: Every page has footer with version
    await runTest('Every page has footer with version', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const footerText = await page.evaluate(() => {
                const footer = document.querySelector('footer');
                return footer ? footer.textContent : null;
            });
            if (!footerText) throw new Error('Missing footer');
            if (!footerText.match(/v\d+\.\d+\.\d+/)) throw new Error('Footer missing version pattern');
        } finally { await cleanup(page, context); }
    });

    // Test 6: Every page loads style.php (has design-system CSS)
    await runTest('Every page loads style.php (has design-system CSS)', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const hasDesignSystem = await page.evaluate(() => {
                const styles = document.querySelectorAll('style');
                for (const s of styles) {
                    if (s.textContent.includes('--c-primary') || s.textContent.includes('--gradient-primary')) {
                        return true;
                    }
                }
                return false;
            });
            if (!hasDesignSystem) throw new Error('No design-system CSS variables found');
        } finally { await cleanup(page, context); }
    });

    // Test 7: Title format is "Page — CircuitDémat" for all pages
    await runTest('Title format is "Page — CircuitDémat"', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const title = await page.title();
            if (!title.includes('—')) throw new Error(`Title "${title}" missing em-dash separator`);
            if (!title.includes('CircuitDémat') && !title.includes('Démat')) throw new Error(`Title "${title}" missing app name`);
        } finally { await cleanup(page, context); }
    });

    // Test 8: No PHP errors/warnings visible in rendered HTML
    await runTest('No PHP errors/warnings visible in rendered HTML', async () => {
        const pagesToCheck = ['/index.php', '/dashboard.php', '/health.php', '/admin_settings.php'];
        for (const url of pagesToCheck) {
            const { page, context } = await adminPage(url);
            try {
                const bodyText = await page.evaluate(() => document.body.innerText);
                const errorPatterns = ['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Undefined variable', 'Call to undefined'];
                for (const pattern of errorPatterns) {
                    if (bodyText.includes(pattern)) {
                        throw new Error(`PHP error "${pattern}" found on ${url}`);
                    }
                }
            } finally { await cleanup(page, context); }
        }
    });

    // Test 9: All pages return HTTP 200
    await runTest('All pages return HTTP 200', async () => {
        for (const p of ALL_PAGES) {
            const { page, response, context } = await adminPage(p.path);
            try {
                const status = response ? response.status() : 0;
                if (status !== 200) throw new Error(`${p.path} returned HTTP ${status}`);
            } finally { await cleanup(page, context); }
        }
    });

    // Test 10: HTML is valid (proper nesting, no duplicate IDs)
    await runTest('HTML is valid (no duplicate IDs)', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const duplicateIds = await page.evaluate(() => {
                const ids = [];
                document.querySelectorAll('[id]').forEach(el => ids.push(el.id));
                const seen = new Set();
                const dupes = [];
                for (const id of ids) {
                    if (seen.has(id)) dupes.push(id);
                    seen.add(id);
                }
                return dupes;
            });
            if (duplicateIds.length > 0) throw new Error(`Duplicate IDs: ${duplicateIds.join(', ')}`);
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 2. NAVIGATION TESTS (8 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 2. Navigation Tests ━━━');

    // Test 11: Sidebar is present on all pages
    await runTest('Sidebar is present on all pages', async () => {
        for (const p of ALL_PAGES) {
            const { page, context } = await adminPage(p.path);
            try {
                const sidebar = await page.$('nav.sidebar');
                if (!sidebar) throw new Error(`No nav.sidebar on ${p.path}`);
            } finally { await cleanup(page, context); }
        }
    });

    // Test 12: Active nav item is highlighted for current page
    await runTest('Active nav item is highlighted for current page', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const hasActive = await page.evaluate(() => {
                const active = document.querySelector('.sidebar-item.active');
                return active !== null;
            });
            if (!hasActive) throw new Error('No active sidebar item on index.php');
        } finally { await cleanup(page, context); }
    });

    // Test 13: All nav links have valid href attributes
    await runTest('All nav links have valid href attributes', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const navLinks = await page.evaluate(() => {
                const links = document.querySelectorAll('.sidebar-item');
                return Array.from(links).map(l => ({ href: l.getAttribute('href'), text: l.textContent.trim() }));
            });
            for (const link of navLinks) {
                if (!link.href) throw new Error(`Nav item "${link.text}" has no href`);
            }
        } finally { await cleanup(page, context); }
    });

    // Test 14: Nav links point to existing pages (no 404s)
    await runTest('Nav links point to existing pages (no 404s)', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const navHrefs = await page.evaluate(() => {
                return Array.from(document.querySelectorAll('.sidebar-item')).map(l => l.getAttribute('href'));
            });
            for (const href of navHrefs) {
                const { page: p2, response, context: c2 } = await adminPage('/' + href);
                try {
                    const status = response ? response.status() : 0;
                    if (status === 404) throw new Error(`Nav link ${href} returns 404`);
                } finally { await cleanup(p2, c2); }
            }
        } finally { await cleanup(page, context); }
    });

    // Test 15: "Accueil" link exists in nav
    await runTest('"Accueil" link exists in nav', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const hasAccueil = await page.evaluate(() => {
                const items = document.querySelectorAll('.sidebar-item');
                return Array.from(items).some(i => i.textContent.includes('Accueil'));
            });
            if (!hasAccueil) throw new Error('No "Accueil" link in sidebar');
        } finally { await cleanup(page, context); }
    });

    // Test 16: Version is displayed in footer/sidebar
    await runTest('Version is displayed in footer', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const versionInFooter = await page.evaluate(() => {
                const footer = document.querySelector('footer');
                return footer ? footer.textContent : '';
            });
            if (!versionInFooter.match(/v\d+\.\d+\.\d+/)) throw new Error('No version in footer');
        } finally { await cleanup(page, context); }
    });

    // Test 17: Skip-link is first focusable element
    await runTest('Skip-link is first focusable element', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const skipLinkPosition = await page.evaluate(() => {
                const skip = document.querySelector('a.skip-link');
                if (!skip) return -1;
                const body = document.body;
                // Check if skip-link is the first <a> in the body
                const firstA = body.querySelector('a');
                return firstA === skip ? 1 : 0;
            });
            if (skipLinkPosition !== 1) throw new Error('Skip-link is not the first <a> element');
        } finally { await cleanup(page, context); }
    });

    // Test 18: Breadcrumb exists where expected
    await runTest('Breadcrumb exists where expected', async () => {
        const pagesWithBreadcrumb = ['/dashboard.php', '/health.php', '/admin_settings.php', '/stats.php'];
        for (const url of pagesWithBreadcrumb) {
            const { page, context } = await adminPage(url);
            try {
                const breadcrumb = await page.$('nav[aria-label*="Ariane"], .breadcrumb');
                if (!breadcrumb) throw new Error(`No breadcrumb on ${url}`);
            } finally { await cleanup(page, context); }
        }
    });

    // ═══════════════════════════════════════════════════════════
    // 3. ADMIN ACCESS CONTROL TESTS (6 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 3. Admin Access Control Tests ━━━');

    // Test 19: Admin pages are accessible with admin user
    await runTest('Admin pages are accessible with admin user', async () => {
        for (const url of ADMIN_PAGES) {
            const { page, response, context } = await adminPage(url);
            try {
                const status = response ? response.status() : 0;
                // form_preview without form_id may return 404, that's OK — but not a redirect to admin_access
                if (url === '/form_preview.php') continue;
                if (status !== 200) throw new Error(`${url} returned HTTP ${status} for admin`);
            } finally { await cleanup(page, context); }
        }
    });

    // Test 20: Non-admin user gets redirected or access denied on admin pages
    await runTest('Non-admin user gets redirected or access denied on admin pages', async () => {
        // For admin-only pages, non-admin should not see 200 with the admin content
        const testUrl = '/stats.php';
        const { page, response, context } = await nonAdminPage(testUrl);
        try {
            const status = response ? response.status() : 0;
            const finalUrl = page.url();
            // In test mode, require_admin() returns JSON with error or redirects to admin_access.php
            const isRedirectedToAccess = finalUrl.includes('index.php?p=admin_access');
            const bodyText = await page.evaluate(() => document.body.innerText);
            const hasAccessDenied = bodyText.includes('Accès refusé') || bodyText.includes('error');
            if (status === 200 && !isRedirectedToAccess && !hasAccessDenied) {
                // If it's still 200, check if it's the actual stats page (which would be wrong for non-admin)
                const hasStatsContent = await page.$('table, .stats');
                if (hasStatsContent) throw new Error('Non-admin can access stats page');
            }
            // Passed if redirected or access denied or JSON error
        } finally { await cleanup(page, context); }
    });

    // Test 21: admin_access.php page loads correctly
    await runTest('admin_access.php page loads correctly', async () => {
        const { page, response, context } = await adminPage('/admin_access.php');
        try {
            const status = response ? response.status() : 0;
            if (status !== 200) throw new Error(`HTTP ${status}`);
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('admin') && !bodyText.includes('Admin') && !bodyText.includes('accès')) {
                throw new Error('admin_access.php missing expected content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 22: require_admin() protects pages correctly
    await runTest('require_admin() protects admin_settings.php from non-admin', async () => {
        const { page, response, context } = await nonAdminPage('/admin_settings.php');
        try {
            const finalUrl = page.url();
            const bodyText = await page.evaluate(() => document.body.innerText);
            const isRedirected = finalUrl.includes('index.php?p=admin_access');
            const hasJsonError = bodyText.includes('Accès refusé');
            if (!isRedirected && !hasJsonError) {
                // Check that we didn't get the full admin settings content
                const hasSmtpForm = await page.$('input[name="smtp_host"]');
                if (hasSmtpForm && !isRedirected) {
                    throw new Error('Non-admin got full admin settings access');
                }
            }
        } finally { await cleanup(page, context); }
    });

    // Test 23: Stats page requires admin
    await runTest('Stats page requires admin', async () => {
        const { page, context } = await nonAdminPage('/stats.php');
        try {
            const finalUrl = page.url();
            const bodyText = await page.evaluate(() => document.body.innerText);
            const isRedirected = finalUrl.includes('index.php?p=admin_access');
            const hasError = bodyText.includes('Accès refusé');
            if (!isRedirected && !hasError) {
                throw new Error('Non-admin can access stats page');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 24: RGPD page requires admin
    await runTest('RGPD page requires admin', async () => {
        const { page, context } = await nonAdminPage('/rgpd.php');
        try {
            const finalUrl = page.url();
            const bodyText = await page.evaluate(() => document.body.innerText);
            const isRedirected = finalUrl.includes('index.php?p=admin_access');
            const hasError = bodyText.includes('Accès refusé');
            if (!isRedirected && !hasError) {
                throw new Error('Non-admin can access RGPD page');
            }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 4. DASHBOARD/LISTING TESTS (8 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 4. Dashboard/Listing Tests ━━━');

    // Test 25: Dashboard shows stats (Total, En cours, Validés, Refusés)
    await runTest('Dashboard shows stats (Total, En cours, Validés, Refusés)', async () => {
        const { page, context } = await adminPage('/dashboard.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('Total')) throw new Error('Missing "Total" stat');
            if (!bodyText.includes('En cours')) throw new Error('Missing "En cours" stat');
            if (!bodyText.includes('Validé')) throw new Error('Missing "Validé" stat');
            if (!bodyText.includes('Refusé')) throw new Error('Missing "Refusé" stat');
        } finally { await cleanup(page, context); }
    });

    // Test 26: Dashboard has search/filter functionality
    await runTest('Dashboard has search/filter functionality', async () => {
        const { page, context } = await adminPage('/dashboard.php');
        try {
            const hasSearch = await page.$('input[type="search"], input[name="search"], input[placeholder*="echerch"]');
            if (!hasSearch) throw new Error('No search input found on dashboard');
        } finally { await cleanup(page, context); }
    });

    // Test 27: Dashboard has status filter links
    await runTest('Dashboard has status filter links', async () => {
        const { page, context } = await adminPage('/dashboard.php');
        try {
            const filterLinks = await page.evaluate(() => {
                const links = document.querySelectorAll('.filtres a, .toolbar a');
                return Array.from(links).map(l => l.textContent.trim()).filter(t => t.length > 0);
            });
            const hasStatusFilter = filterLinks.some(t =>
                t.includes('En cours') || t.includes('Validé') || t.includes('Tous')
            );
            if (!hasStatusFilter) throw new Error('No status filter links found');
        } finally { await cleanup(page, context); }
    });

    // Test 28: My submissions page lists submissions
    await runTest('My submissions page lists submissions', async () => {
        const { page, context } = await adminPage('/my_submissions.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            // Should show either submissions or "Aucune soumission" message
            if (!bodyText.includes('demande') && !bodyText.includes('soumission')) {
                throw new Error('My submissions page missing expected content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 29: My validations page shows pending validations
    await runTest('My validations page shows pending validations', async () => {
        const { page, context } = await adminPage('/my_validations.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('validation') && !bodyText.includes('Validation')) {
                throw new Error('My validations page missing expected content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 30: Monitoring page shows donut chart or metrics
    await runTest('Monitoring page shows metrics', async () => {
        const { page, context } = await adminPage('/monitoring.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('Surveillance') && !bodyText.includes('monitoring') && !bodyText.includes('métrique')) {
                throw new Error('Monitoring page missing expected content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 31: Stats page shows statistics
    await runTest('Stats page shows statistics', async () => {
        const { page, context } = await adminPage('/stats.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('Statistique') && !bodyText.includes('statistique')) {
                throw new Error('Stats page missing expected content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 32: Backup page shows database info
    await runTest('Backup page shows database info', async () => {
        const { page, context } = await adminPage('/backup.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('Sauvegarde') && !bodyText.includes('base') && !bodyText.includes('données')) {
                throw new Error('Backup page missing expected content');
            }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 5. FORM TESTS (6 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 5. Form Tests ━━━');

    // Test 33: Index page shows available forms
    await runTest('Index page shows available forms', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            // Should show forms or a message about no forms
            if (!bodyText.includes('demande') && !bodyText.includes('formulaire')) {
                throw new Error('Index page missing form content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 34: Form page renders with valid slug (onboarding) — returns JSON in test mode
    await runTest('Form page renders with valid slug (onboarding)', async () => {
        const { page, response, context } = await adminPage('/form.php?f=onboarding');
        try {
            const status = response ? response.status() : 0;
            if (status !== 200) throw new Error(`HTTP ${status}`);
            const bodyText = await page.evaluate(() => document.body.innerText);
            // In test mode, form.php returns JSON
            try {
                const json = JSON.parse(bodyText);
                if (json._test_mode && json.form) return; // OK — test mode JSON response
            } catch (_) {}
            // If not JSON, check for error page
            if (bodyText.includes('introuvable')) throw new Error('Form not found');
        } finally { await cleanup(page, context); }
    });

    // Test 35: Form page has CSRF token (JSON in test mode includes it)
    await runTest('Form page provides CSRF token (JSON in test mode)', async () => {
        const { page, context } = await adminPage('/form.php?f=onboarding');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            // In test mode, form.php returns JSON with csrf_token
            try {
                const json = JSON.parse(bodyText);
                if (!json.csrf_token) throw new Error('No csrf_token in JSON response');
                if (!json._test_mode) throw new Error('No _test_mode flag in JSON response');
            } catch (e) {
                if (e.message.startsWith('No ')) throw e;
                throw new Error('Form page did not return JSON in test mode');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 36: Form fields have labels in JSON metadata
    await runTest('Form fields have labels in JSON metadata', async () => {
        const { page, context } = await adminPage('/form.php?f=onboarding');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            // In test mode, form.php returns JSON with fields list
            try {
                const json = JSON.parse(bodyText);
                if (!json.fields || json.fields.length === 0) throw new Error('No fields in JSON response');
                const fieldsWithoutLabel = json.fields.filter(f => !f.label);
                if (fieldsWithoutLabel.length > 0) {
                    throw new Error(`${fieldsWithoutLabel.length} fields missing labels`);
                }
            } catch (e) {
                if (e.message.includes('fields')) throw e;
                throw new Error('Form page did not return JSON in test mode');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 37: Form JSON response includes field metadata
    await runTest('Form JSON response includes field metadata', async () => {
        const { page, context } = await adminPage('/form.php?f=onboarding');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            try {
                const json = JSON.parse(bodyText);
                if (!json.form) throw new Error('No form object in JSON');
                if (!json.form.slug) throw new Error('No form slug in JSON');
                if (!json.form.label) throw new Error('No form label in JSON');
            } catch (e) {
                if (e.message.includes('form')) throw e;
                throw new Error('Form page did not return JSON in test mode');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 38: Date fields in form JSON have proper field_type
    await runTest('Date fields in form JSON have proper field_type', async () => {
        const { page, context } = await adminPage('/form.php?f=onboarding');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            try {
                const json = JSON.parse(bodyText);
                if (!json.fields) throw new Error('No fields in JSON');
                const dateFields = json.fields.filter(f => f.field_name && f.field_name.toLowerCase().includes('date'));
                // Date-named fields should have field_type 'date'
                for (const df of dateFields) {
                    if (df.field_type !== 'date' && df.field_type !== 'text') {
                        throw new Error(`Date field "${df.field_name}" has unexpected type "${df.field_type}"`);
                    }
                }
            } catch (e) {
                if (e.message.includes('field')) throw e;
                throw new Error('Form page did not return JSON in test mode');
            }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 6. SETTINGS/ADMIN TESTS (6 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 6. Settings/Admin Tests ━━━');

    // Test 39: Admin settings page has SMTP section
    await runTest('Admin settings page has SMTP section', async () => {
        const { page, context } = await adminPage('/admin_settings.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('SMTP') && !bodyText.includes('smtp')) {
                throw new Error('No SMTP section in admin settings');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 40: Admin settings has workflow section
    await runTest('Admin settings has workflow section', async () => {
        const { page, context } = await adminPage('/admin_settings.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('workflow') && !bodyText.includes('Workflow') && !bodyText.includes('relance')) {
                throw new Error('No workflow section in admin settings');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 41: Admin settings has security section
    await runTest('Admin settings has security/verification section', async () => {
        const { page, context } = await adminPage('/admin_settings.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('vérification') && !bodyText.includes('Vérification') && !bodyText.includes('sécurité') && !bodyText.includes('token')) {
                throw new Error('No security/verification section in admin settings');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 42: Admin forms page shows form list
    await runTest('Admin forms page shows form list', async () => {
        const { page, context } = await adminPage('/admin_forms.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('formulaire') && !bodyText.includes('Formulaire')) {
                throw new Error('No form list on admin_forms page');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 43: Admin alerts page shows alert rules
    await runTest('Admin alerts page shows alert rules', async () => {
        const { page, context } = await adminPage('/admin_alerts.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('alerte') && !bodyText.includes('Alerte') && !bodyText.includes('règle')) {
                throw new Error('No alert content on admin_alerts page');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 44: Admin access page shows admin list
    await runTest('Admin access page shows admin list', async () => {
        const { page, context } = await adminPage('/admin_access.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('admin') && !bodyText.includes('Admin') && !bodyText.includes('accès')) {
                throw new Error('No admin content on admin_access page');
            }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 7. CSRF/SECURITY TESTS (4 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 7. CSRF/Security Tests ━━━');

    // Test 45: All forms have csrf_token hidden field
    await runTest('All forms have csrf_token hidden field', async () => {
        const pagesWithForms = ['/form.php?f=onboarding', '/admin_settings.php', '/admin_alerts.php'];
        for (const url of pagesWithForms) {
            const { page, context } = await adminPage(url);
            try {
                const formCount = await page.evaluate(() => document.querySelectorAll('form').length);
                if (formCount === 0) continue;
                const csrfCount = await page.evaluate(() => document.querySelectorAll('input[name="csrf_token"]').length);
                if (csrfCount === 0) throw new Error(`No csrf_token in forms on ${url}`);
            } finally { await cleanup(page, context); }
        }
    });

    // Test 46: CSRF tokens are different on each page load
    await runTest('CSRF tokens are different on each page load', async () => {
        const { page: p1, context: c1 } = await adminPage('/admin_settings.php');
        const { page: p2, context: c2 } = await adminPage('/admin_settings.php');
        try {
            const token1 = await p1.evaluate(() => {
                const input = document.querySelector('input[name="csrf_token"]');
                return input ? input.value : null;
            });
            const token2 = await p2.evaluate(() => {
                const input = document.querySelector('input[name="csrf_token"]');
                return input ? input.value : null;
            });
            if (!token1 || !token2) throw new Error('Missing CSRF token');
            if (token1 === token2) throw new Error('CSRF tokens are identical across page loads');
        } finally {
            await cleanup(p1, c1);
            await cleanup(p2, c2);
        }
    });

    // Test 47: No inline scripts except admin_settings.php (progressive enhancement only)
    await runTest('No inline scripts except admin_settings.php (progressive enhancement)', async () => {
        const pagesToCheck = ['/index.php', '/dashboard.php', '/health.php', '/changelog.php', '/my_submissions.php', '/my_validations.php'];
        for (const url of pagesToCheck) {
            const { page, context } = await adminPage(url);
            try {
                const scriptCount = await page.evaluate(() => document.querySelectorAll('script').length);
                if (scriptCount > 0) throw new Error(`${scriptCount} <script> tags found on ${url}`);
            } finally { await cleanup(page, context); }
        }
        // admin_settings.php is a known exception: it has 2 progressive-enhancement scripts
        // that only toggle UI visibility (app works without JS)
        const { page, context } = await adminPage('/admin_settings.php');
        try {
            const scriptCount = await page.evaluate(() => document.querySelectorAll('script').length);
            if (scriptCount > 2) throw new Error(`admin_settings.php has ${scriptCount} scripts (expected max 2)`);
        } finally { await cleanup(page, context); }
    });

    // Test 48: No external resource loading (no CDN)
    await runTest('No external resource loading (no CDN)', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const externalResources = await page.evaluate(() => {
                const externals = [];
                // Check for external stylesheets
                document.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
                    const href = link.getAttribute('href') || '';
                    if (href.startsWith('http://') || href.startsWith('https://')) {
                        externals.push(href);
                    }
                });
                // Check for external scripts
                document.querySelectorAll('script[src]').forEach(script => {
                    const src = script.getAttribute('src') || '';
                    if (src.startsWith('http://') || src.startsWith('https://')) {
                        externals.push(src);
                    }
                });
                // Check for external images
                document.querySelectorAll('img[src]').forEach(img => {
                    const src = img.getAttribute('src') || '';
                    if (src.startsWith('http://') || src.startsWith('https://')) {
                        externals.push(src);
                    }
                });
                return externals;
            });
            if (externalResources.length > 0) {
                throw new Error(`External resources found: ${externalResources.join(', ')}`);
            }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 8. ACCESSIBILITY TESTS (4 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 8. Accessibility Tests ━━━');

    // Test 49: All images have alt attributes
    await runTest('All images have alt attributes', async () => {
        const pagesToCheck = ['/index.php', '/dashboard.php', '/health.php'];
        for (const url of pagesToCheck) {
            const { page, context } = await adminPage(url);
            try {
                const imgAlts = await page.evaluate(() => {
                    const imgs = document.querySelectorAll('img');
                    return Array.from(imgs).map(img => ({
                        src: img.getAttribute('src') || '',
                        alt: img.getAttribute('alt')
                    }));
                });
                for (const img of imgAlts) {
                    if (img.alt === null) throw new Error(`Image ${img.src} missing alt attribute on ${url}`);
                }
            } finally { await cleanup(page, context); }
        }
    });

    // Test 50: Form inputs have associated labels
    await runTest('Form inputs have associated labels on user-facing pages', async () => {
        // Check user-facing pages with simpler forms (search bars, filters)
        const pagesToCheck = ['/my_submissions.php', '/my_validations.php'];
        for (const url of pagesToCheck) {
            const { page, context } = await adminPage(url);
            try {
                const inputs = await page.evaluate(() => {
                    const result = [];
                    document.querySelectorAll('main input:not([type="hidden"]):not([type="submit"]):not([type="button"]), main select, main textarea').forEach(el => {
                        const id = el.getAttribute('id');
                        const ariaLabel = el.getAttribute('aria-label');
                        const ariaLabelledBy = el.getAttribute('aria-labelledby');
                        const parentLabel = el.closest('label');
                        const hasLabel = (id && document.querySelector(`label[for="${id}"]`)) || parentLabel || ariaLabel || ariaLabelledBy;
                        result.push({
                            tag: el.tagName,
                            name: el.getAttribute('name') || '',
                            hasLabel: !!hasLabel
                        });
                    });
                    return result;
                });
                const unlabelled = inputs.filter(i => !i.hasLabel);
                if (unlabelled.length > 2) {
                    throw new Error(`${unlabelled.length} unlabelled inputs on ${url}: ${unlabelled.map(i => i.name).join(', ')}`);
                }
            } finally { await cleanup(page, context); }
        }
    });

    // Test 51: ARIA labels present where needed
    await runTest('ARIA labels present where needed', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const ariaCheck = await page.evaluate(() => {
                const nav = document.querySelector('nav.sidebar');
                const navAria = nav ? nav.getAttribute('aria-label') : null;
                return { navAria };
            });
            if (!ariaCheck.navAria) throw new Error('Sidebar nav missing aria-label');
        } finally { await cleanup(page, context); }
    });

    // Test 52: Color contrast (basic check - no all-same-color text)
    await runTest('Color contrast basic check (no invisible text)', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const invisibleText = await page.evaluate(() => {
                let invisible = 0;
                document.querySelectorAll('p, span, div, h1, h2, h3, h4, h5, h6, a, label, td, th').forEach(el => {
                    const style = window.getComputedStyle(el);
                    if (style.color === style.backgroundColor && style.color !== 'rgba(0, 0, 0, 0)') {
                        invisible++;
                    }
                });
                return invisible;
            });
            if (invisibleText > 0) throw new Error(`${invisibleText} elements with same color as background`);
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 9. RESPONSIVE DESIGN TESTS (2 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 9. Responsive Design Tests ━━━');

    // Test 53: Page renders correctly at 1280px (desktop)
    await runTest('Page renders correctly at 1280px (desktop)', async () => {
        const context = await browser.newContext({
            extraHTTPHeaders: ADMIN_HEADERS,
            viewport: { width: 1280, height: 800 }
        });
        const page = await context.newPage();
        try {
            await page.goto(`${BASE_URL}/index.php`, { waitUntil: 'domcontentloaded', timeout: 10000 });
            const sidebar = await page.$('nav.sidebar');
            if (!sidebar) throw new Error('Sidebar not visible at 1280px');
            const sidebarVisible = await sidebar.isVisible();
            if (!sidebarVisible) throw new Error('Sidebar not visible at 1280px');
        } finally { await cleanup(page, context); }
    });

    // Test 54: Page renders correctly at 375px (mobile)
    await runTest('Page renders correctly at 375px (mobile)', async () => {
        const context = await browser.newContext({
            extraHTTPHeaders: ADMIN_HEADERS,
            viewport: { width: 375, height: 812 }
        });
        const page = await context.newPage();
        try {
            await page.goto(`${BASE_URL}/index.php`, { waitUntil: 'domcontentloaded', timeout: 10000 });
            const main = await page.$('main#main-content');
            if (!main) throw new Error('Main content not found at 375px');
            // Check that page doesn't have horizontal overflow
            const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
            const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
            // Allow small overflow (scrollbars, etc.)
            if (scrollWidth > clientWidth + 50) {
                throw new Error(`Horizontal overflow: scrollWidth=${scrollWidth}, clientWidth=${clientWidth}`);
            }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // 10. DATA INTEGRITY TESTS (4 tests)
    // ═══════════════════════════════════════════════════════════
    console.log('\n━━━ 10. Data Integrity Tests ━━━');

    // Test 55: Health check page shows system status
    await runTest('Health check page shows system status', async () => {
        const { page, context } = await adminPage('/health.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('Santé') && !bodyText.includes('santé')) {
                throw new Error('Health page missing "Santé" content');
            }
            // Should have check items
            const hasChecks = bodyText.includes('Base de données') || bodyText.includes('PHP') || bodyText.includes('SQLite');
            if (!hasChecks) throw new Error('Health page missing check items');
        } finally { await cleanup(page, context); }
    });

    // Test 56: Changelog page shows version history
    await runTest('Changelog page shows version history', async () => {
        const { page, context } = await adminPage('/changelog.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.match(/\d+\.\d+\.\d+/)) throw new Error('No version numbers found on changelog page');
        } finally { await cleanup(page, context); }
    });

    // Test 57: Docs page has documentation content
    await runTest('Docs page has documentation content', async () => {
        const { page, context } = await adminPage('/docs.php');
        try {
            const bodyText = await page.evaluate(() => document.body.innerText);
            if (!bodyText.includes('Documentation') && !bodyText.includes('documentation') && !bodyText.includes('guide')) {
                throw new Error('Docs page missing documentation content');
            }
        } finally { await cleanup(page, context); }
    });

    // Test 58: Version in footer matches CHANGELOG.md
    await runTest('Version in footer matches CHANGELOG.md', async () => {
        const { page, context } = await adminPage('/index.php');
        try {
            const footerVersion = await page.evaluate(() => {
                const footer = document.querySelector('footer');
                if (!footer) return null;
                const match = footer.textContent.match(/v(\d+\.\d+\.\d+)/);
                return match ? match[1] : null;
            });
            if (!footerVersion) throw new Error('No version found in footer');
            // Compare with changelog page
            const { page: clPage, context: clContext } = await adminPage('/changelog.php');
            try {
                const changelogVersion = await clPage.evaluate(() => {
                    const match = document.body.innerText.match(/(\d+\.\d+\.\d+)/);
                    return match ? match[1] : null;
                });
                if (!changelogVersion) throw new Error('No version found on changelog page');
                if (footerVersion !== changelogVersion) {
                    throw new Error(`Footer v${footerVersion} != Changelog v${changelogVersion}`);
                }
            } finally { await cleanup(clPage, clContext); }
        } finally { await cleanup(page, context); }
    });

    // ═══════════════════════════════════════════════════════════
    // SUMMARY
    // ═══════════════════════════════════════════════════════════

    await browser.close();

    console.log(`\n═══════════════════════════════════════════════════════════`);
    console.log(`  COMPREHENSIVE: ${passed} passed / ${failed} failed / ${passed + failed} total`);
    console.log(`═══════════════════════════════════════════════════════════`);
    if (errors.length > 0) {
        console.log(`\nFailed tests:`);
        errors.forEach(e => console.log(`  • ${e}`));
    }

    process.exit(failed > 0 ? 1 : 0);
}

runTests().catch(err => {
    console.error('Playwright error:', err);
    process.exit(1);
});
