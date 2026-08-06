// run_all.js — Orchestrateur qui lance tous les tests e2e Playwright.
//
// Exécute chaque fichier .spec.js du dossier tests/e2e/ via child_process
// (un process Node par spec → isolation complète : pas de partage de serveur
// PHP, de context Playwright, ni de variables globales).
//
// Affiche un résumé final avec le statut de chaque spec et un code de sortie
// global (0 si tout est OK, 1 si au moins une spec a échoué).
//
// Usage : node tests/e2e/run_all.js
//         node tests/e2e/run_all.js --no-smoke   (pour skipper smoke.spec.js)

const { execSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const E2E_DIR = __dirname;

// ─── Couleurs ANSI (désactivables via NO_COLOR) ───────────────────
const USE_COLOR = process.stdout.isTTY && !process.env.NO_COLOR;
function c(code, str) {
    if (!USE_COLOR) return str;
    return `\x1b[${code}m${str}\x1b[0m`;
}
const GREEN  = (s) => c('32', s);
const RED    = (s) => c('31', s);
const YELLOW = (s) => c('33', s);
const BOLD   = (s) => c('1', s);
const DIM    = (s) => c('2', s);

// ─── Liste des specs à exécuter ──────────────────────────────────
// On liste explicitement (au lieu de glob) pour contrôler l'ordre d'exécution :
// smoke d'abord (le plus rapide — si smoke casse, pas la peine d'aller plus loin),
// puis admin_pages (vérif auth), puis les tests fonctionnels (submission, validation).
function getSpecs() {
    const exclude = new Set(process.argv.slice(2)
        .filter((a) => a.startsWith('--no-'))
        .map((a) => a.slice(5) + '.spec.js'));

    const allSpecs = [
        'smoke.spec.js',
        'assets_css_pure.spec.js',
        'admin_pages.spec.js',
        'validation_flow.spec.js',
        'full_submission_flow.spec.js',
        'visual_styles.spec.js',
    ];

    // Filtrer celles qui existent réellement sur disque + ne sont pas exclues
    return allSpecs.filter((s) => {
        if (exclude.has(s)) return false;
        const full = path.join(E2E_DIR, s);
        return fs.existsSync(full);
    });
}

// ─── Helpers ─────────────────────────────────────────────────────

function printHeader() {
    console.log(BOLD('═══════════════════════════════════════════════════════════════'));
    console.log(BOLD('  Tests e2e Playwright — CircuitDémat'));
    console.log(BOLD('  Dossier : tests/e2e/'));
    console.log(BOLD('═══════════════════════════════════════════════════════════════'));
    console.log();
}

function printFooter(results) {
    const total = results.length;
    const ok = results.filter((r) => r.exitCode === 0).length;
    const ko = total - ok;
    const totalDuration = results.reduce((sum, r) => sum + r.durationMs, 0);

    console.log();
    console.log(BOLD('═══════════════════════════════════════════════════════════════'));
    console.log(BOLD('  RÉSUMÉ E2E'));
    console.log(BOLD('═══════════════════════════════════════════════════════════════'));
    console.log(`  ${'SPEC'.padEnd(36)} ${'STATUT'.padEnd(10)} ${'DURÉE'.padEnd(10)}`);
    console.log(`  ${'─'.repeat(36)} ${'─'.repeat(10)} ${'─'.repeat(10)}`);
    for (const r of results) {
        const status = r.exitCode === 0 ? GREEN('OK') : RED('ÉCHEC');
        const duration = (r.durationMs / 1000).toFixed(1) + 's';
        console.log(`  ${r.name.padEnd(36)} ${status.padEnd(10)} ${duration.padEnd(10)}`);
    }
    console.log(`  ${'─'.repeat(36)} ${'─'.repeat(10)} ${'─'.repeat(10)}`);
    const summary = `${ok} réussi(s) / ${ko} échoué(s) / ${total} total`;
    const colored = ko === 0 ? GREEN(summary) : RED(summary);
    console.log(`  ${colored}  ${DIM(`(${(totalDuration / 1000).toFixed(1)}s au total)`)}`);
    console.log(BOLD('═══════════════════════════════════════════════════════════════'));
}

// ─── Exécution d'une spec ────────────────────────────────────────
function runSpec(specName) {
    const specPath = path.join(E2E_DIR, specName);
    const start = Date.now();

    console.log(BOLD(`\n▶ Exécution : ${specName}`));
    console.log(DIM(`  Commande : node ${specPath}`));
    console.log(DIM(`  Début    : ${new Date().toLocaleTimeString()}`));

    let exitCode = 0;
    let stdout = '';
    let stderr = '';

    try {
        // spawnSync avec stdio 'inherit' pour voir le output en temps réel,
        // MAIS on capture aussi pour le résumé. On utilise 'pipe' + console.log.
        // En fait, le plus simple : inherit pour le live, et on compte sur
        // le code de sortie.
        const result = spawnSync('node', [specPath], {
            cwd: E2E_DIR,
            stdio: 'inherit',
            encoding: 'utf8',
            timeout: 120000, // 2 min max par spec
        });

        exitCode = result.status ?? 1;
        if (result.error) {
            console.error(RED(`  [run_all] Erreur spawn : ${result.error.message}`));
            exitCode = 1;
        }
        if (result.signal) {
            console.error(RED(`  [run_all] Spec tuée par signal ${result.signal}`));
            exitCode = 1;
        }
    } catch (e) {
        console.error(RED(`  [run_all] Exception : ${e.message}`));
        exitCode = 1;
    }

    const durationMs = Date.now() - start;
    const statusStr = exitCode === 0 ? GREEN('OK') : RED('ÉCHEC');
    console.log(DIM(`  Fin      : ${new Date().toLocaleTimeString()} (${(durationMs / 1000).toFixed(1)}s)`));
    console.log(`  ${BOLD('Statut')} : ${statusStr}`);

    return { name: specName, exitCode, durationMs };
}

// ─── Main ────────────────────────────────────────────────────────
function main() {
    printHeader();

    // Vérifier que node et playwright sont disponibles avant de lancer
    try {
        execSync('node -e "require(\'playwright\')"', { stdio: 'ignore' });
    } catch (_) {
        console.error(RED('Erreur : Playwright n\'est pas installé.'));
        console.error(DIM('  Installez-le avec : npm install playwright'));
        process.exit(2);
    }

    const specs = getSpecs();
    if (specs.length === 0) {
        console.error(YELLOW('Aucun spec à exécuter.'));
        process.exit(0);
    }

    console.log(`  Specs à exécuter (${specs.length}) : ${specs.join(', ')}\n`);

    const results = [];
    for (const spec of specs) {
        const r = runSpec(spec);
        results.push(r);
        // Fail-fast optionnel : on continue même si un spec échoue pour avoir
        // le résumé complet. Pour fail-fast, décommenter les 2 lignes suivantes :
        // if (r.exitCode !== 0) break;
    }

    printFooter(results);

    const failedCount = results.filter((r) => r.exitCode !== 0).length;
    process.exit(failedCount > 0 ? 1 : 0);
}

main();
