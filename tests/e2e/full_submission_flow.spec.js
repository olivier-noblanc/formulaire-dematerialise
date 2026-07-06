// full_submission_flow.spec.js — Test e2e du scénario complet de soumission
// d'un formulaire par un agent (Approche A : page.fill + page.check + page.click).
//
// Étapes :
//   1. Démarrer serveur PHP avec AUTH_USER: DREETS\admin.local (admin)
//   2. GET /index.php?p=form&f=onboarding
//   3. Extraire le CSRF token du HTML (input[name=csrf_token])
//   4. Vérifier que la page contient <form id="form-main">, la checkbox rgpd_consent
//      et le bouton "Envoyer ma demande"
//   5. Remplir les champs obligatoires (page.fill + page.selectOption),
//      cocher rgpd_consent (page.check), puis cliquer sur "Envoyer ma demande"
//   6. Vérifier que la page de succès :
//        - contient "Demande enregistrée"
//        - NE contient PAS <input type="checkbox" name="rgpd_consent" (bug P0 historique)
//        - NE contient PAS "Envoyer ma demande"
//   7. Vérifier qu'aucune date ISO (regex /\b\d{4}-\d{2}-\d{2}\b/) n'est visible
//      dans le HTML (hors attribut value= des inputs)
//
// Note : ce test crée RÉELLEMENT une soumission dans workflow.db (sans TEST_MODE,
// la DB réelle est utilisée). Les emails ne sont pas envoyés car mail_dry_run=1
// par défaut dans config.php. Le test ne nettoie pas la DB — l'orchestrateur CTO
// décidera de la stratégie de cleanup (chaque exécution ajoute une ligne).
//
// Usage : node tests/e2e/full_submission_flow.spec.js

const {
    TestRun,
    startTestServer,
    launchBrowser,
    newContext,
    getPageHtml,
    getCsrfToken,
    assertContains,
    assertNotContains,
} = require('./helpers');

// ─── Valeurs de test pour les champs du formulaire onboarding ────
// Champs obligatoires (basés sur la table form_fields du form onboarding) :
// nom, prenom, date_naissance, date_prise_poste, corps_grade, type_arrivee,
// affectation, quotite, type_poste, log_batiment_bureau + rgpd_consent
//
// Séparation inputs texte/date vs selects — les premiers utilisent page.fill(),
// les seconds page.selectOption(). On ne peut pas appeler page.fill() sur un
// <select> (Playwright lève une erreur).
const TEXT_INPUT_FIELDS = {
    nom: 'Doe',
    prenom: 'Jane',
    date_naissance: '1985-06-15',         // format ISO attendu par <input type="date">
    date_prise_poste: '2024-09-01',
    affectation: 'DREETS Bourgogne-Franche-Comté',
    log_batiment_bureau: 'Bâtiment A - Bureau 123',
};
const SELECT_FIELDS = {
    corps_grade: 'Attaché d\'administration',
    type_arrivee: 'Primo-recrutement',
    quotite: '100%',
    type_poste: 'Fixe',
};

async function main() {
    const t = new TestRun();
    let stop, browser;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer('DREETS\\admin.local'));
        console.log('  Serveur prêt.\n');

        browser = await launchBrowser();
        const context = await newContext(browser, 'DREETS\\admin.local');

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 1-4 : GET /index.php?p=form&f=onboarding et vérifier le rendu initial
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 1 — Chargement du formulaire onboarding');
        const page = await context.newPage();
        const { html: formHtml, status: formStatus } = await getPageHtml(
            page,
            '/index.php?p=form&f=onboarding'
        );

        if (formStatus === 200) {
            t.ok('GET /index.php?p=form&f=onboarding → HTTP 200');
        } else {
            t.ko('GET /index.php?p=form&f=onboarding → HTTP 200', `status=${formStatus}`);
        }

        // Étape 3 — Extraction du CSRF token
        const csrfToken = await getCsrfToken(page);
        if (csrfToken && csrfToken.length >= 32) {
            t.ok(`CSRF token extrait (longueur=${csrfToken.length})`);
        } else {
            t.ko('CSRF token extrait', `token="${csrfToken}"`);
        }

        // Étape 4 — Vérifier la présence des éléments attendus
        assertContains(formHtml, 'id="form-main"', 'Form contient <form id="form-main">', t);
        assertContains(
            formHtml,
            'name="rgpd_consent"',
            'Form contient la checkbox rgpd_consent',
            t
        );
        assertContains(
            formHtml,
            'Envoyer ma demande',
            'Form contient le bouton "Envoyer ma demande"',
            t
        );

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 5 : Remplir tous les champs obligatoires + cocher RGPD
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 2 — Remplissage des champs obligatoires');

        // Champs texte et date (input type=text/date) — on utilise page.fill()
        for (const [name, value] of Object.entries(TEXT_INPUT_FIELDS)) {
            const selector = `input[name="${name}"]`;
            try {
                // Vérifier que le champ existe avant de fill
                const count = await page.locator(selector).count();
                if (count === 0) {
                    t.ko(`Champ "${name}" présent dans la page`, 'aucun input trouvé');
                    continue;
                }
                await page.fill(selector, value);
                t.ok(`Champ "${name}" rempli avec "${value}"`);
            } catch (e) {
                t.ko(`Champ "${name}" rempli avec "${value}"`, e.message);
            }
        }

        // Champs select — utilisation de selectOption (page.fill ne marche pas sur <select>)
        for (const [name, value] of Object.entries(SELECT_FIELDS)) {
            const selector = `select[name="${name}"]`;
            try {
                const count = await page.locator(selector).count();
                if (count === 0) {
                    t.ko(`Select "${name}" présent`, 'aucun select trouvé');
                    continue;
                }
                await page.selectOption(selector, value);
                t.ok(`Select "${name}" → "${value}"`);
            } catch (e) {
                t.ko(`Select "${name}" → "${value}"`, e.message);
            }
        }

        // Cocher la checkbox RGPD
        try {
            await page.check('input[name="rgpd_consent"]');
            const isChecked = await page.isChecked('input[name="rgpd_consent"]');
            if (isChecked) {
                t.ok('Checkbox rgpd_consent cochée');
            } else {
                t.ko('Checkbox rgpd_consent cochée', 'isChecked=false après check()');
            }
        } catch (e) {
            t.ko('Checkbox rgpd_consent cochée', e.message);
        }

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 5bis : Soumettre le formulaire (click sur le bouton submit)
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 3 — Soumission du formulaire');

        // On attend la navigation déclenchée par le submit.
        // La navigation doit retourner une réponse (même URL en POST → GET reload
        // avec succès). On utilise Promise.all pour ne pas rater l'événement.
        let submitOk = false;
        let submitError = '';
        try {
            // Le bouton submit est <button type="submit" class="btn-submit">✓ Envoyer ma demande</button>
            const submitButton = page.locator('button[type="submit"].btn-submit').first();
            const buttonVisible = await submitButton.isVisible().catch(() => false);
            if (!buttonVisible) {
                throw new Error('Bouton submit non visible');
            }

            // Click + attendre la navigation (la page se recharge sur form.php?f=onboarding)
            await Promise.all([
                page.waitForLoadState('domcontentloaded', { timeout: 15000 }),
                submitButton.click(),
            ]);

            // Robustesse anti-flaky : attendre que la page contienne SOIT le message
            // de succès (.success) SOIT le formulaire ré-affiché (form#form-main)
            // SOIT une erreur. On attend jusqu'à 5s pour être sûr que le POST a été
            // traité et que le HTML est stabilisé avant de lire page.content().
            await page.waitForFunction(
                () => document.querySelector('.success') !== null
                    || document.querySelector('form#form-main') !== null
                    || document.querySelector('.err') !== null
                    || document.querySelector('.msg-error') !== null,
                { timeout: 8000 }
            ).catch(() => {
                // Si le waitForFunction timeout, on continue quand même — les
                // assertions suivantes détecteront l'absence de "Demande enregistrée"
            });

            submitOk = true;
        } catch (e) {
            submitError = e.message;
        }

        if (submitOk) {
            t.ok('Formulaire soumis (bouton cliqué + navigation terminée)');
        } else {
            t.ko('Formulaire soumis (bouton cliqué + navigation terminée)', submitError);
        }

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 6 : Vérifier la page de succès
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 4 — Vérification de la page de succès');

        const successHtml = await page.content();

        // 6.a — Contient "Demande enregistrée"
        assertContains(successHtml, 'Demande enregistrée', 'Page succès contient "Demande enregistrée"', t);

        // DEBUG : si la substring n'est pas là, on log un extrait pour comprendre
        if (!successHtml.includes('Demande enregistrée')) {
            // Chercher des messages d'erreur connus
            const errorPatterns = [
                /Ce champ est obligatoire/g,
                /Vous devez accepter le traitement/g,
                /class="err"/g,
                /class="msg-error"/g,
                /Token CSRF invalide/g,
            ];
            const foundErrors = [];
            for (const p of errorPatterns) {
                const m = successHtml.match(p);
                if (m) foundErrors.push(`${p} × ${m.length}`);
            }
            // Extraire un extrait autour du <form> ou du <main>
            const mainStart = successHtml.indexOf('<main');
            const excerpt = mainStart >= 0
                ? successHtml.substring(mainStart, mainStart + 1500).replace(/\s+/g, ' ')
                : successHtml.substring(0, 1500).replace(/\s+/g, ' ');
            console.log('  [DEBUG] Erreurs détectées :', foundErrors.join(', ') || 'aucune pattern d\'erreur');
            console.log('  [DEBUG] Extrait HTML :', excerpt.substring(0, 600) + (excerpt.length > 600 ? '...' : ''));
        }

        // 6.b — NE contient PAS la checkbox RGPD (bug P0 historique : endif mal placé
        //        faisait fuiter rgpd_consent + bouton submit sous le message succès)
        assertNotContains(
            successHtml,
            '<input type="checkbox" name="rgpd_consent"',
            'Page succès NE contient PAS la checkbox rgpd_consent (bug P0)',
            t
        );

        // 6.c — NE contient PAS le bouton "Envoyer ma demande"
        assertNotContains(
            successHtml,
            'Envoyer ma demande',
            'Page succès NE contient PAS le bouton "Envoyer ma demande"',
            t
        );

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 7 : Vérifier qu'aucune date ISO visible n'est dans le HTML
        // (hors attribut value= des inputs)
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 5 — Vérification anti-dates ISO visibles');

        // Stripping des <input value="..."> pour ne pas matcher les valeurs
        // saisies par l'utilisateur (les dates sont légitimement au format ISO
        // dans les inputs type=date).
        const htmlWithoutInputValues = successHtml.replace(
            /<input[^>]*\bvalue="[^"]*"[^>]*>/gi,
            '<input-stripped>'
        );

        // Pareil pour les attributs value="" des options
        const htmlWithoutValues = htmlWithoutInputValues.replace(
            /<option[^>]*\bvalue="[^"]*"[^>]*>/gi,
            '<option-stripped>'
        );

        const isoDateMatch = htmlWithoutValues.match(/\b\d{4}-\d{2}-\d{2}\b/);
        if (!isoDateMatch) {
            t.ok('Aucune date ISO visible dans le HTML (hors attributs value)');
        } else {
            // Trouver le contexte autour du match pour débug
            const idx = htmlWithoutValues.indexOf(isoDateMatch[0]);
            const context = htmlWithoutValues
                .substring(Math.max(0, idx - 80), idx + 80)
                .replace(/\s+/g, ' ');
            t.ko(
                'Aucune date ISO visible dans le HTML (hors attributs value)',
                `trouvé "${isoDateMatch[0]}" — contexte: ...${context}...`
            );
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
