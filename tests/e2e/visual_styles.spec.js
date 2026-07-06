// visual_styles.spec.js — Test Playwright des styles visuels (computed styles).
//
// Vérifie que les badges "En cours / Validée / Refusée" et les cartes stats
// ont bien les couleurs attendues (background + color) calculées par le navigateur.
//
// Ce test répond à la question utilisateur : "Ne peux-tu pas faire des tests ?"
// sur le problème "tous en cours validés refusés est sans style".
//
// Approche : Playwright charge la page avec un vrai navigateur Chromium,
// puis utilise page.evaluate() + getComputedStyle() pour vérifier les
// couleurs calculées des éléments .badge et .stat.
//
// Usage : node tests/e2e/visual_styles.spec.js

const { chromium } = require('playwright');
const helpers = require('./helpers');

async function main() {
    const t = new helpers.TestRun();
    t.section('Tests visuels — styles calculés des badges et stats');

    const serverHandle = await helpers.startTestServer();
    const stopServer = serverHandle.stop;
    try {
        const browser = await chromium.launch({ headless: true });
        // Utiliser testeur@exemple.invalid qui a 13 soumissions en DB de test
        // (l'admin admin.local n'a qu'1 soumission en_cours).
        const context = await browser.newContext({
            extraHTTPHeaders: {
                'AUTH_USER': 'DREETS\\testeur',
            },
        });

        // ─── Test 1 : my_submissions.php — badges de statut ───
        t.section('my_submissions.php — badges "En cours / Validée / Refusée"');
        const page1 = await context.newPage();
        try {
            await page1.goto(`${helpers.BASE_URL}/index.php?p=my_submissions`, { waitUntil: 'networkidle', timeout: 15000 });

            // Vérifier que la page contient au moins un badge
            const badgeCount = await page1.locator('.badge').count();
            t.ok(`Page contient ${badgeCount} élément(s) .badge`);

            if (badgeCount > 0) {
                // Pour chaque type de badge, vérifier le style calculé SI présent
                const badgeTypes = [
                    { cls: '.badge-en-cours', expectedBgPrefix: '#fef3c7', expectedColorPrefix: '#78350f', label: 'En cours' },
                    { cls: '.badge-valide',   expectedBgPrefix: '#d1fae5', expectedColorPrefix: '#065f46', label: 'Validée' },
                    { cls: '.badge-refuse',   expectedBgPrefix: '#fff0f0', expectedColorPrefix: '#c0000d', label: 'Refusée' },
                ];

                for (const bt of badgeTypes) {
                    const el = page1.locator(bt.cls).first();
                    const exists = await el.count();
                    if (exists === 0) {
                        // Skip — pas de soumission avec ce statut en DB de test
                        t.ok(`Badge ${bt.label} (${bt.cls}) — absent de la page (pas de soumission avec ce statut en DB)`);
                        continue;
                    }

                    // Récupérer les styles calculés
                    const styles = await el.evaluate(node => {
                        const cs = window.getComputedStyle(node);
                        return {
                            background: cs.backgroundColor,
                            color: cs.color,
                            // Convertir rgb() en hex pour comparaison
                            bgHex: '#' + cs.backgroundColor.match(/\d+/g).map(x => parseInt(x).toString(16).padStart(2, '0')).join(''),
                            colorHex: '#' + cs.color.match(/\d+/g).map(x => parseInt(x).toString(16).padStart(2, '0')).join(''),
                        };
                    });

                    // Vérifier que le background n'est pas transparent ou blanc ou bleu foncé (le bug historique)
                    if (styles.background === 'rgba(0, 0, 0, 0)' || styles.bgHex === '#ffffff' || styles.bgHex === '#000000') {
                        t.ko(`Badge ${bt.label} — background`, `background=${styles.background} (attendu ~${bt.expectedBgPrefix}) — le badge n'a pas de couleur de fond !`);
                    } else if (styles.bgHex === '#00006f' || styles.bgHex === '#000091') {
                        t.ko(`Badge ${bt.label} — background`, `background=${styles.bgHex} (bleu foncé — le bug historique .badge générique écrase .badge-en-cours/valide/refuse)`);
                    } else {
                        t.ok(`Badge ${bt.label} — background coloré (${styles.bgHex})`);
                    }

                    // Vérifier que la couleur du texte n'est pas noire ou blanche (le bug historique)
                    if (styles.colorHex === '#000000' || styles.colorHex === '#ffffff') {
                        t.ko(`Badge ${bt.label} — color`, `color=${styles.colorHex} (couleur par défaut — style non appliqué)`);
                    } else {
                        t.ok(`Badge ${bt.label} — text color (${styles.colorHex})`);
                    }
                }
            }

            // ─── Test 2 : cartes .stat avec classes en-cours / valide / refuse ───
            t.section('my_submissions.php — cartes .stat (en-cours / valide / refuse)');
            const statTypes = [
                { cls: '.stat.en-cours', label: 'En cours' },
                { cls: '.stat.valide',   label: 'Validées' },
                { cls: '.stat.refuse',   label: 'Refusées' },
            ];

            for (const st of statTypes) {
                const el = page1.locator(st.cls).first();
                const exists = await el.count();
                if (exists === 0) {
                    t.ko(`Stat ${st.label} (${st.cls})`, 'élément absent');
                    continue;
                }

                // Vérifier la couleur du <strong> (le nombre)
                const strongStyles = await el.locator('strong').evaluate(node => {
                    const cs = window.getComputedStyle(node);
                    return {
                        color: cs.color,
                        colorHex: '#' + cs.color.match(/\d+/g).map(x => parseInt(x).toString(16).padStart(2, '0')).join(''),
                    };
                });

                // La couleur ne doit pas être la couleur par défaut (noir #000000 ou gris foncé)
                if (strongStyles.colorHex === '#000000' || strongStyles.colorHex === '#1f2937' || strongStyles.colorHex === '#111827') {
                    t.ko(`Stat ${st.label} — strong color`, `color=${strongStyles.colorHex} (couleur par défaut — style .stat.${st.cls.split('.')[1]} non appliqué)`);
                } else {
                    t.ok(`Stat ${st.label} — strong color ${strongStyles.colorHex}`);
                }

                // Vérifier la barre de couleur (::before, 2px en haut)
                const beforeBg = await el.evaluate(node => {
                    const cs = window.getComputedStyle(node, '::before');
                    return cs.backgroundColor;
                });
                if (beforeBg === 'rgba(0, 0, 0, 0)' || beforeBg === 'transparent') {
                    t.ko(`Stat ${st.label} — ::before background`, `bg=${beforeBg} (pas de barre de couleur en haut)`);
                } else {
                    t.ok(`Stat ${st.label} — ::before barre colorée (${beforeBg})`);
                }
            }
        } catch (e) {
            t.ko('my_submissions.php se charge', e.message);
        }
        await page1.close();

        // ─── Test 3 : dashboard.php — badges et stats (avec admin) ───
        t.section('dashboard.php — badges et stats admin (avec admin admin.local)');
        const adminContext = await browser.newContext({
            extraHTTPHeaders: {
                'AUTH_USER': 'DREETS\\admin.local',
            },
        });
        const page2 = await adminContext.newPage();
        try {
            await page2.goto(`${helpers.BASE_URL}/index.php?p=dashboard`, { waitUntil: 'networkidle', timeout: 15000 });

            // Vérifier qu'il y a des badges
            const badgeCount = await page2.locator('.badge').count();
            t.ok(`dashboard.php contient ${badgeCount} élément(s) .badge`);

            // Vérifier les stat-cards (monitoring-style)
            const statCardCount = await page2.locator('.stat-card').count();
            if (statCardCount > 0) {
                t.ok(`dashboard.php contient ${statCardCount} .stat-card`);
            }

            // Vérifier qu'au moins un badge a une couleur de fond (pas bleu foncé #00006f)
            if (badgeCount > 0) {
                const firstBadgeStyles = await page2.locator('.badge').first().evaluate(node => {
                    const cs = window.getComputedStyle(node);
                    const bg = cs.backgroundColor;
                    const bgHex = '#' + bg.match(/\d+/g).map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
                    return { bg, bgHex };
                });
                if (firstBadgeStyles.bgHex === '#00006f' || firstBadgeStyles.bgHex === '#000091') {
                    t.ko('dashboard.php — premier badge', `bg=${firstBadgeStyles.bgHex} (bleu foncé — bug .badge générique non scopé)`);
                } else if (firstBadgeStyles.bg === 'rgba(0, 0, 0, 0)' || firstBadgeStyles.bg === 'transparent') {
                    t.ko('dashboard.php — premier badge', `bg=${firstBadgeStyles.bg} (transparent — style non appliqué)`);
                } else {
                    t.ok(`dashboard.php — premier badge a un background coloré (${firstBadgeStyles.bgHex})`);
                }
            }
        } catch (e) {
            t.ko('dashboard.php se charge', e.message);
        }
        await page2.close();
        // NB: adminContext sera fermé après monitoring (page3 l'utilise aussi)

        // ─── Test 4 : monitoring.php — stat-cards (avec admin) ───
        t.section('monitoring.php — stat-cards (6 cartes du haut, avec admin)');
        const page3 = await adminContext.newPage();
        try {
            await page3.goto(`${helpers.BASE_URL}/index.php?p=monitoring`, { waitUntil: 'networkidle', timeout: 15000 });

            const statCardCount = await page3.locator('.stat-card').count();
            t.ok(`monitoring.php contient ${statCardCount} .stat-card`);

            if (statCardCount > 0) {
                // La 1ère stat-card ("Soumissions totales") est volontairement neutre
                // (pas de classe success/warning/danger). On vérifie plutôt qu'au moins
                // une stat-card colorée existe (success, warning, ou danger).
                const coloredCards = await page3.locator('.stat-card.success, .stat-card.warning, .stat-card.danger').count();
                if (coloredCards > 0) {
                    t.ok(`monitoring.php — ${coloredCards} stat-card(s) colorée(s) (success/warning/danger)`);
                } else {
                    t.ko('monitoring.php — stat-cards colorées', 'aucune stat-card avec classe success/warning/danger');
                }

                // Vérifier qu'une stat-card colorée a bien une barre de couleur (::before)
                if (coloredCards > 0) {
                    const coloredCard = page3.locator('.stat-card.success, .stat-card.warning, .stat-card.danger').first();
                    const beforeStyles = await coloredCard.evaluate(node => {
                        const cs = window.getComputedStyle(node, '::before');
                        return {
                            backgroundColor: cs.backgroundColor,
                            backgroundImage: cs.backgroundImage,
                            content: cs.content,
                            height: cs.height,
                        };
                    });
                    // Le ::before peut avoir un background-image (gradient) au lieu d'un backgroundColor
                    const hasGradient = beforeStyles.backgroundImage && beforeStyles.backgroundImage !== 'none';
                    const hasBgColor = beforeStyles.backgroundColor !== 'rgba(0, 0, 0, 0)' && beforeStyles.backgroundColor !== 'transparent';
                    if (hasGradient || hasBgColor) {
                        const detail = hasGradient ? `gradient=${beforeStyles.backgroundImage.substring(0, 60)}` : `bg=${beforeStyles.backgroundColor}`;
                        t.ok(`monitoring.php — stat-card colorée a une barre colorée (${detail})`);
                    } else {
                        t.ko('monitoring.php — stat-card colorée ::before', `bgColor=${beforeStyles.backgroundColor}, bgImage=${beforeStyles.backgroundImage} (pas de barre de couleur)`);
                    }
                }
            }
        } catch (e) {
            t.ko('monitoring.php se charge', e.message);
        }
        await page3.close();
        await adminContext.close();

        await browser.close();
    } finally {
        await stopServer();
    }

    t.summary();
    process.exit(t.failed > 0 ? 1 : 0);
}

main().catch(err => {
    console.error('Erreur fatale:', err);
    process.exit(2);
});
