// validation_flow.spec.js — Test e2e du scénario de validation par un validateur.
//
// Étapes :
//   1. Démarrer serveur PHP avec auth validateur (DREETS\responsable.direct)
//      → responsable.direct@dreets.gouv.fr a des tokens en attente en DB
//   2. GET /index.php?p=index?p=my_validations → vérifier status 200
//   3. Si au moins une validation en attente → cliquer sur "Valider / Refuser"
//   4. Vérifier que validate.php se charge (status 200)
//   5. Vérifier que la page contient :
//        - <form method="post" id="validation-form">
//        - Les radios de motif de refus (input[type=radio][name=motif])
//        - Le textarea "comment"
//        - Le bouton "Valider"
//
// Note importante : ce test ne soumet PAS la validation (pour ne pas modifier
// la DB). Il vérifie juste que la page de validation est correctement rendue.
//
// Si la DB ne contient aucune validation en attente pour responsable.direct,
// le test s'arrête en succès "skip" (pas d'échec — l'état de la DB peut varier).
//
// Usage : node tests/e2e/validation_flow.spec.js

const {
    TestRun,
    startTestServer,
    launchBrowser,
    newContext,
    getPageHtml,
    assertContains,
} = require('./helpers');

// Validateuse avec des tokens en attente dans la DB de test
const VALIDATOR_AUTH = 'DREETS\\responsable.direct';

async function main() {
    const t = new TestRun();
    let stop, browser;
    try {
        console.log('── Démarrage du serveur PHP (port 8900) ──');
        ({ stop } = await startTestServer(VALIDATOR_AUTH));
        console.log('  Serveur prêt.\n');

        browser = await launchBrowser();
        const context = await newContext(browser, VALIDATOR_AUTH);

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 1-2 : GET /index.php?p=index?p=my_validations et vérifier 200
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 1 — Chargement de /index.php?p=index?p=my_validations');
        const page = await context.newPage();
        const { html: valHtml, status: valStatus } = await getPageHtml(
            page,
            '/index.php?p=index?p=my_validations'
        );

        if (valStatus === 200) {
            t.ok('GET /index.php?p=index?p=my_validations → HTTP 200');
        } else {
            t.ko('GET /index.php?p=index?p=my_validations → HTTP 200', `status=${valStatus}`);
        }

        // Vérifier que la page contient le titre "Mes validations"
        assertContains(valHtml, 'Mes validations', 'Page contient "Mes validations"', t);

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 3 : Chercher un lien "Valider / Refuser" pour une validation
        //           en attente. Si aucun → on skip la suite (test success).
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 2 — Recherche d\'une validation en attente');

        // Le lien est : <a href="validate.php?token=..." class="btn btn-primary">...Valider / Refuser</a>
        const validateLink = await page.locator('a[href^="validate.php?token="]').first();
        const linkCount = await page.locator('a[href^="validate.php?token="]').count();

        if (linkCount === 0) {
            // Pas de validation en attente — pas un échec (l'état de la DB varie)
            t.ok('Aucune validation en attente (skip — pas un échec)');
            console.log('\n  [INFO] Aucune validation en attente pour ce validateur.');
            console.log('  [INFO] Le reste du test est skippé (la DB peut varier entre runs).\n');
            console.log(t.summary());
            return;
        }

        t.ok(`Au moins une validation en attente trouvée (${linkCount} lien(s))`);

        // Extraire le href du premier lien pour pouvoir le valider directement
        // (au cas où le click n'aboutirait pas)
        const href = await validateLink.getAttribute('href');
        if (!href) {
            t.ko('Lien "Valider / Refuser" a un href', 'href vide ou null');
            console.log(t.summary());
            return;
        }
        t.ok(`Lien "Valider / Refuser" trouvé : ${href.substring(0, 60)}...`);

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 4 : Cliquer sur le lien et vérifier que validate.php se charge
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 3 — Navigation vers validate.php');

        // Navigation directe via l'URL (plus robuste qu'un click qui peut déclencher
        // du JS non désiré). On construit l'URL absolue à partir du href relatif.
        const fullUrl = new URL(href, page.url()).toString();
        const validateResp = await page.goto(fullUrl, {
            waitUntil: 'domcontentloaded',
            timeout: 15000,
        });
        const validateStatus = validateResp ? validateResp.status() : null;

        if (validateStatus === 200) {
            t.ok('GET validate.php?token=... → HTTP 200');
        } else {
            t.ko('GET validate.php?token=... → HTTP 200', `status=${validateStatus}`);
        }

        // ═══════════════════════════════════════════════════════════════
        // ÉTAPE 5 : Vérifier le rendu de la page de validation
        // ═══════════════════════════════════════════════════════════════
        t.section('Étape 4 — Vérification du rendu de validate.php');

        const vHtml = await page.content();

        // 5.a — Le formulaire de validation principal
        assertContains(
            vHtml,
            'method="post" id="validation-form"',
            'Page contient <form method="post" id="validation-form">',
            t
        );

        // 5.b — Les radios de motif de refus (input[type=radio][name=motif])
        const motifRadioCount = await page.locator('input[type="radio"][name="motif"]').count();
        if (motifRadioCount >= 4) {
            t.ok(`Radios de motif de refus présents (${motifRadioCount} radios)`);
        } else {
            t.ko(
                'Radios de motif de refus présents (au moins 4)',
                `trouvé ${motifRadioCount} radio(s)`
            );
        }

        // Vérifier la présence de motifs spécifiques (4 motifs codés en dur dans validate.php)
        assertContains(vHtml, 'Information manquante', 'Motif "Information manquante" présent', t);
        assertContains(vHtml, 'Hors périmètre', 'Motif "Hors périmètre" présent', t);
        assertContains(vHtml, 'Non conforme', 'Motif "Non conforme" présent', t);
        assertContains(vHtml, 'Autre motif', 'Motif "Autre motif" présent', t);

        // 5.c — Le textarea "comment"
        const commentTextareaCount = await page.locator('textarea#comment[name="comment"]').count();
        if (commentTextareaCount >= 1) {
            t.ok('Textarea "comment" présent');
        } else {
            t.ko('Textarea "comment" présent', `count=${commentTextareaCount}`);
        }

        // 5.d — Le bouton "Valider"
        const validateButtonCount = await page
            .locator('button[type="submit"][name="action"][value="valider"]')
            .count();
        if (validateButtonCount >= 1) {
            t.ok('Bouton "Valider" présent (submit action=valider)');
        } else {
            t.ko('Bouton "Valider" présent (submit action=valider)', `count=${validateButtonCount}`);
        }

        // 5.e — Vérifier qu'on n'est PAS sur une page d'erreur (Lien invalide / expiré / déjà traité)
        //       (Cela peut arriver si le token a été consommé entre-temps par un autre test)
        const isErrorPage = vHtml.includes('Lien invalide')
            || vHtml.includes('Lien expiré')
            || vHtml.includes('Déjà validé')
            || vHtml.includes('Workflow terminé');
        if (!isErrorPage) {
            t.ok('Page de validation active (pas page d\'erreur "Lien invalide/expiré/déjà validé")');
        } else {
            t.ko(
                'Page de validation active (pas page d\'erreur)',
                'page d\'erreur détectée — token probablement consommé'
            );
        }

        // 5.f — Vérifier qu'il y a aussi un bouton "Confirmer le refus"
        //       (U-04 — Refus mobile frictionnel)
        assertContains(vHtml, 'Confirmer le refus', 'Bouton "Confirmer le refus" présent', t);

        // 5.g — Vérifier le champ caché "token" dans le formulaire
        const tokenInputCount = await page
            .locator('form#validation-form input[type="hidden"][name="token"]')
            .count();
        if (tokenInputCount >= 1) {
            t.ok('Champ caché "token" dans le form#validation-form');
        } else {
            t.ko('Champ caché "token" dans le form#validation-form', `count=${tokenInputCount}`);
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
