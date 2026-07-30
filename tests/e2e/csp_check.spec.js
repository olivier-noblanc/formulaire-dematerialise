// csp_check.spec.js — Vérifie la conformité CSP des pages critiques.
//
// Pour chaque page :
//   1. Injecte un listener SecurityPolicyViolationEvent AVANT le chargement
//   2. Charge la page, vérifie le header Content-Security-Policy
//   3. Compte les inline styles (style=) et <style> pour le suivi
//   4. échoue si des violations CSP sont détectées par le navigateur
//
// Le CSP actuel autorise 'unsafe-inline' → pas de violations runtime tant que
// le inline n'est pas supprimé. Ce test servira de filet de sécurité dès que
// 'unsafe-inline' sera retiré du header CSP.
//
// Usage : node tests/e2e/csp_check.spec.js

const {
    TestRun,
    startTestServer,
    launchBrowser,
    newContext,
    getPageHtml,
} = require('./helpers');

// ─── Pages à vérifier ─────────────────────────────────────────────
const PAGES = [
    { url: '/index.php?p=admin_forms',           label: 'admin_forms' },
    { url: '/index.php?p=admin_settings',        label: 'admin_settings' },
    { url: '/index.php?p=monitoring',            label: 'monitoring' },
    { url: '/index.php?p=admin_access',          label: 'admin_access' },
    { url: '/index.php?p=admin_alerts',          label: 'admin_alerts' },
    { url: '/index.php?p=dashboard',             label: 'dashboard' },
    { url: '/index.php?p=stats',                 label: 'stats' },
    { url: '/index.php?p=docs',                  label: 'docs' },
    { url: '/index.php?p=changelog',             label: 'changelog' },
    { url: '/',                                  label: 'index' },
    { url: '/index.php?p=form&f=onboarding',     label: 'form (onboarding)' },
];

// Script injecté AVANT le chargement de la page via addInitScript().
// Capture les violations CSP et les inline styles pour rapport.
const CSP_INIT_SCRIPT = `
window.__cspViolations = [];
window.__cspInlineStyleCount = 0;
window.__cspInlineScriptCount = 0;

// Listener CSP violations (SecurityPolicyViolationEvent)
document.addEventListener('securitypolicyviolation', function(e) {
    window.__cspViolations.push({
        directive: e.violatedDirective,
        blocked: e.blockedURI,
        source: e.sourceFile,
        line: e.lineNumber,
        column: e.columnNumber,
        sample: (e.sample || '').substring(0, 200),
    });
});

// Comptage inline styles et scripts au chargement
window.addEventListener('DOMContentLoaded', function() {
    // style= attributes
    var allEls = document.querySelectorAll('[style]');
    window.__cspInlineStyleCount = allEls.length;
    // <style> tags
    var styleTags = document.querySelectorAll('style');
    window.__cspInlineTagCount = styleTags.length;
    // <script> tags sans nonce
    var scripts = document.querySelectorAll('script:not([nonce])');
    window.__cspInlineScriptCount = scripts.length;
});
`;

async function main() {
    const t = new TestRun();
    let stop, browser;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer('DREETS\\admin.local'));
        console.log('  Serveur prêt.\n');

        browser = await launchBrowser();
        const context = await newContext(browser, 'DREETS\\admin.local');

        t.section('CSP — Vérification du header Content-Security-Policy');

        for (const p of PAGES) {
            const page = await context.newPage();
            try {
                // Injecter le listener de violations AVANT le chargement
                await page.addInitScript(CSP_INIT_SCRIPT);

                const { html, status, resp } = await getPageHtml(page, p.url, { timeout: 15000 });

                // 1. Vérifier la présence du header CSP
                const cspHeader = resp ? resp.headers()['content-security-policy'] : null;
                if (cspHeader) {
                    t.ok(`[${p.label}] Header CSP présent`);

                    // Vérifier les directives critiques
                    if (cspHeader.includes("script-src")) {
                        t.ok(`[${p.label}] script-src présent`);
                    } else {
                        t.ko(`[${p.label}] script-src présent`, 'directive script-src manquante');
                    }
                    if (cspHeader.includes("style-src")) {
                        t.ok(`[${p.label}] style-src présent`);
                    } else {
                        t.ko(`[${p.label}] style-src présent`, 'directive style-src manquante');
                    }
                    if (cspHeader.includes("frame-ancestors")) {
                        t.ok(`[${p.label}] frame-ancestors présent`);
                    } else {
                        t.ko(`[${p.label}] frame-ancestors présent`, 'directive frame-ancestors manquante');
                    }

                    // Alerte si 'unsafe-inline' est présent sur style-src (attendu
                    // pour l'instant, migration en cours — cf. TODO.md). Ne devrait
                    // plus jamais apparaître sur script-src (retiré le 2026-07-30).
                    if (/style-src[^;]*'unsafe-inline'/.test(cspHeader)) {
                        console.log(`  ⚠️  [${p.label}] 'unsafe-inline' présent sur style-src — migration en cours`);
                    }
                    if (/script-src[^;]*'unsafe-inline'/.test(cspHeader)) {
                        t.ko(`[${p.label}] script-src sans unsafe-inline`, "'unsafe-inline' ne devrait plus être présent sur script-src");
                    }
                } else {
                    t.ko(`[${p.label}] Header CSP présent`, 'Content-Security-Policy absent');
                }

                // 2. Attendre que DOMContentLoaded ait fires pour les compteurs
                await page.waitForTimeout(200);

                // 3. Lire les violations CSP capturées par le navigateur.
                // script-src : échec dur (plus de fallback unsafe-inline depuis
                // le 2026-07-30, cf. TODO.md § CSP — zéro inline).
                // style-src : avertissement seulement — migration des style=""
                // dynamiques vers des <style nonce> ciblés en cours, pas encore
                // terminée (voir TODO.md pour le volume mesuré par page).
                const violations = await page.evaluate(() => window.__cspViolations || []);
                const scriptViolations = violations.filter(v => v.directive.startsWith('script-src'));
                const styleViolations = violations.filter(v => v.directive.startsWith('style-src'));
                const otherViolations = violations.filter(v => !v.directive.startsWith('script-src') && !v.directive.startsWith('style-src'));

                if (scriptViolations.length === 0) {
                    t.ok(`[${p.label}] Aucune violation script-src runtime`);
                } else {
                    t.ko(
                        `[${p.label}] Aucune violation script-src runtime`,
                        `${scriptViolations.length} violation(s) : ${scriptViolations.map(v => v.directive + ' (' + v.blocked + ')').join(', ')}`
                    );
                }

                if (styleViolations.length > 0) {
                    console.log(`  ⚠️  [${p.label}] ${styleViolations.length} violation(s) style-src (migration en cours, non bloquant — cf. TODO.md)`);
                }

                if (otherViolations.length === 0) {
                    t.ok(`[${p.label}] Aucune autre violation CSP runtime`);
                } else {
                    t.ko(
                        `[${p.label}] Aucune autre violation CSP runtime`,
                        `${otherViolations.length} violation(s) : ${otherViolations.map(v => v.directive + ' (' + v.blocked + ')').join(', ')}`
                    );
                }

                // Log détaillé (toutes violations, y compris style-src en info)
                for (const v of violations) {
                    console.log(`    ⛔ ${v.directive} — ${v.blocked} @ ${v.source}:${v.line}:${v.column}`);
                    if (v.sample) console.log(`       sample: ${v.sample}`);
                }

                // 4. Comptage inline (informationnel — pas un échec tant que unsafe-inline)
                const inlineStyles = await page.evaluate(() => window.__cspInlineStyleCount || 0);
                const inlineStyleTags = await page.evaluate(() => window.__cspInlineTagCount || 0);
                const inlineScripts = await page.evaluate(() => window.__cspInlineScriptCount || 0);
                if (inlineStyles > 0 || inlineStyleTags > 0 || inlineScripts > 0) {
                    console.log(`  ℹ️  [${p.label}] inline: ${inlineStyles} style= attr, ${inlineStyleTags} <style> tags, ${inlineScripts} <script> sans nonce`);
                }

            } catch (e) {
                t.ko(`[${p.label}] chargement de la page`, e.message);
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
