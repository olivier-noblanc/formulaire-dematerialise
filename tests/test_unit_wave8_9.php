<?php
/**
 * tests/test_unit_wave8_9.php — Sections 16+17 : Wave 8 (v5.25.3 bugs) + Wave 9 (S4-TESTS runtime HTTP + t_jargon)
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Sections 16+17 : Wave 8 (v5.25.3 bugs) + Wave 9 (S4-TESTS runtime HTTP + t_jargon)
 */
function run_tests_unit_wave8_9(): void {
echo "── 16. Tests Wave 8 — v5.25.3 (3 bugs prod découverts par l'utilisateur) ──\n";

// ── 16.1 Bug 1 : migration v12 — table drafts doit exister même si schema_version marquée ──
test('Bug 1 — table drafts existe en DB test (auto-réparation)', function() {
    // Le test vérifie que la table drafts existe après db_migrate()
    // Bug v5.25.2 prod : si la migration échouait, schema_version était marquée à 12 mais la table n'existait pas
    $pdo = get_pdo();
    $table_exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='drafts'")->fetchColumn();
    release_pdo();
    if ($table_exists !== 'drafts') {
        return 'Table drafts manquante — auto-réparation db_migrate() ne fonctionne pas';
    }
    return true;
});

// ── 16.2 Bug 2 — alert_check.php et remind.php doivent vérifier _lazy_cron_running ──
test('Bug 2 — alert_check.php vérifie _lazy_cron_running (pas de exit en lazy_cron)', function() {
    $code = file_get_contents(dirname(__DIR__) . '/alert_check.php');
    if (strpos($code, '_lazy_cron_running') === false) {
        return 'alert_check.php ne vérifie pas _lazy_cron_running — le lazy_cron web ferait exit()';
    }
    return true;
});

test('Bug 2 — remind.php vérifie _lazy_cron_running (pas de exit en lazy_cron)', function() {
    $code = file_get_contents(dirname(__DIR__) . '/remind.php');
    if (strpos($code, '_lazy_cron_running') === false) {
        return 'remind.php ne vérifie pas _lazy_cron_running — le lazy_cron web ferait exit()';
    }
    return true;
});

test('Bug 2 — run_lazy_cron positionne _lazy_cron_running = true avant require', function() {
    $code = file_get_contents(dirname(__DIR__) . '/helpers.php');
    if (strpos($code, '$GLOBALS[\'_lazy_cron_running\'] = true') === false) {
        return 'run_lazy_cron ne positionne pas _lazy_cron_running — les scripts CLI feront exit() en web';
    }
    return true;
});

// ── 16.3 Bug 3 — parse_changelog ne doit pas utiliser "---" comme séparateur ──
test('Bug 3 — parse_changelog ne traite plus "---" comme séparateur de version', function() {
    $code = file_get_contents(dirname(__DIR__) . '/changelog.php');
    // L'ancien code avait : if ($trimmed === '---') { ... $versions[] = $current_version; ... }
    // Le nouveau code ne doit plus avoir cette logique
    if (preg_match('/if\s*\(\s*\$trimmed\s*===\s*[\'"]---[\'"]\s*\)\s*\{/', $code)) {
        return 'parse_changelog traite encore "---" comme séparateur — bug v5.25.2 non corrigé';
    }
    return true;
});

test('Bug 3 — parse_changelog parse au moins 40 versions (était bloqué à ~10 avant)', function() {
    require_once dirname(__DIR__) . '/changelog.php';
    $versions = parse_changelog(dirname(__DIR__) . '/CHANGELOG.md');
    if (count($versions) < 40) {
        return 'Trop peu de versions parsées : ' . count($versions) . ' (attendu >= 40)';
    }
    // S4-TESTS : la 1re version doit être la plus récente du CHANGELOG.
    // ITER2 : 5.26.0 est maintenant la plus récente (avant c'était 5.25.3, avant 5.25.2).
    // On utilise version_compare pour ne plus casser à chaque version.
    $latest = $versions[0]['version'];
    if (version_compare($latest, '5.26.0', '<')) {
        return '1re version = ' . $latest . ' (attendu >= 5.26.0 — parser ne voit pas la version la plus récente)';
    }
    return true;
});

test('Bug 3 — parse_changelog inclut les anciennes versions (jusqu\'à 1.x)', function() {
    require_once dirname(__DIR__) . '/changelog.php';
    $versions = parse_changelog(dirname(__DIR__) . '/CHANGELOG.md');
    $last = end($versions);
    // Le CHANGELOG.md commence à la version 1.0.0
    if (version_compare($last['version'], '2.0.0', '>=')) {
        return 'Dernière version = ' . $last['version'] . ' — parser s\'arrête trop tôt (attendu < 2.0.0)';
    }
    return true;
});

echo "\n";

// ═══════════════════════════════════════════════════
// 17. TESTS WAVE 9 — S4-TESTS (tests runtime HTTP + t_jargon)
//   - Action 7  : tests runtime HTTP systématiques (bugs prod non vus par tests unitaires)
//   - Action 9  : helper _find_function_in_libs() défini en section 12.13 (refactor tests inspection source)
//   - Action sup. : tests t_jargon() (recommandés par S4-UI Action 1, VÉTO 1 M. Robert)
//   - Fix       : test 16.3 mis à jour (5.25.2 → 5.25.3) — voir section 16 ci-dessus.
//
// CONTEXTE :
// - v5.25.2/v5.25.3 : 3 bugs prod découverts par un utilisateur (Wave 8) + refonte UI Sprint 4
//   (t_jargon, "Actions avancées" visibles, tutoriel 1ère utilisation, section "En résumé"
//   du changelog). Les tests unitaires n'avaient pas détecté ces bugs car ils n'invoquaient
//   pas les pages HTTP. Cette section 17 ajoute des smoke tests HTTP via subprocess PHP CLI
//   (pattern identique au test 15.4 — Wave 7 S3-TESTER).
// - t_jargon() a été ajoutée par S4-UI dans helpers.php (9 mappings jargon → français).
//   Cette section ajoute 12 tests (9 mappings + idempotence + préservation CircuitDémat +
//   faux positif EPIsode).
// ═══════════════════════════════════════════════════
echo "── 17. Tests Wave 9 — S4-TESTS (runtime HTTP + t_jargon) ──\n";

// ── 17.1 — Tests t_jargon() (Action 1 S4-UI / VÉTO 1 M. Robert) ──────────────────
// 12 tests : 9 mappings + idempotence + préservation CircuitDémat + faux positif EPIsode.
// t_jargon() est définie dans helpers.php (chargée via test_bootstrap.php).

test('t_jargon — Dématérialisation → Demande en ligne', function() {
    $result = t_jargon('Dématérialisation des procédures');
    return strpos($result, 'Demande en ligne') !== false
        ? true : "Traduction manquante : $result";
});

test('t_jargon — Circuit de validation → Étapes de validation', function() {
    $result = t_jargon('Circuit de validation à 3 étapes');
    return strpos($result, 'Étapes de validation') !== false
        ? true : "Traduction manquante : $result";
});

test('t_jargon — Workflow → Parcours (casse sensible)', function() {
    // "Workflow" (W majuscule) doit devenir "Parcours" (P majuscule).
    // On vérifie aussi que "Workflow" n'est plus présent dans le résultat.
    $result = t_jargon('Workflow de validation');
    if (strpos($result, 'Parcours') === false) return "Parcours manquant : $result";
    if (strpos($result, 'Workflow') !== false) return "Workflow encore présent : $result";
    return true;
});

test('t_jargon — Token → Lien de validation', function() {
    $result = t_jargon('Token de sécurité');
    return strpos($result, 'Lien de validation') !== false
        ? true : "Traduction Token manquante : $result";
});

test('t_jargon — Slug → Nom technique', function() {
    $result = t_jargon('Slug du formulaire');
    return strpos($result, 'Nom technique') !== false
        ? true : "Traduction Slug manquante : $result";
});

test('t_jargon — Quotité → Temps de travail (en %)', function() {
    $result = t_jargon('Quotité : 80%');
    return strpos($result, 'Temps de travail (en %)') !== false
        ? true : "Traduction Quotité manquante : $result";
});

test('t_jargon — EPI → Équipement de protection individuelle (EPI)', function() {
    $result = t_jargon('EPI obligatoire');
    return strpos($result, 'Équipement de protection individuelle (EPI)') !== false
        ? true : "Traduction EPI manquante : $result";
});

test('t_jargon — CSRF → Code de sécurité', function() {
    $result = t_jargon('CSRF invalide');
    return strpos($result, 'Code de sécurité') !== false
        ? true : "Traduction CSRF manquante : $result";
});

test('t_jargon — tokens (pluriel, minuscule) → liens de validation', function() {
    // Test le 9e mapping : tokens (pluriel) → liens de validation (pluriel).
    $result = t_jargon('2 tokens actifs');
    return strpos($result, 'liens de validation') !== false
        ? true : "Traduction tokens (pluriel) manquante : $result";
});

test('t_jargon — idempotence (EPI 2× ne double-traduit pas)', function() {
    // t_jargon() doit être idempotente : appeler 2× sur la même chaîne ne doit pas
    // doubler la traduction (ex. "Équipement de protection individuelle (EPI)" →
    // ne doit pas devenir "Équipement de protection individuelle (Équipement de
    // protection individuelle (EPI))"). S4-UI garantit cela via placeholders \x01/\x02.
    $once = t_jargon('EPI obligatoire');
    $twice = t_jargon($once);
    return $once === $twice ? true : "Non idempotent : once='$once', twice='$twice'";
});

test('t_jargon — CircuitDémat préservé (nom de l\'app)', function() {
    // Le nom de l'application "CircuitDémat" contient "Démat" qui pourrait être
    // confondu avec "Dématérialisation". S4-UI le préserve via placeholder \x01.
    $result = t_jargon('Bienvenue sur CircuitDémat v5.25.3');
    return strpos($result, 'CircuitDémat') !== false
        ? true : "Nom de l'app altéré : $result";
});

test('t_jargon — faux positif (EPIsode non touché, frontière de mot \\b)', function() {
    // "EPIsode" contient "EPI" mais ne doit pas être traduit (frontière de mot \b).
    // S4-UI utilise preg_replace('/\bEPI\b/u', ...) qui respecte les frontières de mot.
    $result = t_jargon('EPIsode de la série');
    return strpos($result, 'Équipement') === false
        ? true : "Faux positif : EPI dans EPIsode a été traduit → $result";
});

// ── 17.2 — Tests runtime HTTP (Action 7) ─────────────────────────────────────────
// Les bugs prod v5.25.2/v5.25.3 n'ont pas été vus par les tests unitaires.
// On lance les pages via subprocess PHP CLI (pattern identique au test 15.4 — Wave 7)
// avec APP_TEST_MODE=1 + headers X-Test-Mode / X-Test-User, puis on parse les marqueurs.

// Note : le helper _run_http_subprocess() est défini dans tests/test_unit_helpers.php
// (chargé via require_once dans test_unit.php). On l'utilise directement ici.

test('Runtime HTTP my_submissions.php — 200 OK, pas de "Ce script ne peut", pas de "no such table"', function() {
    // Bug prod v5.25.2 : my_submissions.php affichait "Ce script ne peut être exécuté..."
    // ou "no such table" à cause du bug get_delegations() (S2-TESTER) + table drafts
    // manquante (Wave 8 Bug 1). Vérifie qu'après fixes S3+S4, la page se rend proprement.
    $test_user = 'runtime_s4_my_subs_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    $markers = _run_http_subprocess(dirname(__DIR__) . '/my_submissions.php', $test_user);
    $errors = [];
    if (!empty($markers['EXCEPTION'])) $errors[] = 'Exception : ' . $markers['EXCEPTION'];
    if (!empty($markers['HAS_FATAL']) && $markers['HAS_FATAL'] === '1') $errors[] = 'Page contient "Fatal error"';
    if (!empty($markers['HAS_PARSE_ERROR']) && $markers['HAS_PARSE_ERROR'] === '1') $errors[] = 'Page contient "Parse error"';
    if (!empty($markers['HAS_CE_SCRIPT']) && $markers['HAS_CE_SCRIPT'] === '1') $errors[] = 'Bug v5.25.2 : "Ce script ne peut" présent';
    if (!empty($markers['HAS_NO_SUCH_TABLE']) && $markers['HAS_NO_SUCH_TABLE'] === '1') $errors[] = '"no such table" présent';
    if (!empty($markers['HAS_NO_SUCH_COLUMN']) && $markers['HAS_NO_SUCH_COLUMN'] === '1') $errors[] = 'Bug S2-TESTER : "no such column" présent';
    if (!empty($markers['HAS_PDOEXCEPTION']) && $markers['HAS_PDOEXCEPTION'] === '1') $errors[] = 'PDOException présent';
    if (empty($markers['HAS_DOCTYPE']) || $markers['HAS_DOCTYPE'] !== '1') $errors[] = 'DOCTYPE manquant (page non rendue)';
    return empty($errors) ? true : implode(' | ', $errors);
});

test('Runtime HTTP changelog.php — 200 OK, contient "5.25.3", "En résumé", au moins 40 versions', function() {
    // S4-CHANGELOG a ajouté la section "En résumé" en haut de page. La version la plus
    // récente est 5.25.3. Le parser doit afficher >= 40 versions (48 attendues).
    $admin = get_admin_email();
    if (empty($admin)) return 'admin_email non configuré en DB test';
    $markers = _run_http_subprocess(dirname(__DIR__) . '/changelog.php', $admin);
    $errors = [];
    if (!empty($markers['EXCEPTION'])) $errors[] = 'Exception : ' . $markers['EXCEPTION'];
    if (!empty($markers['HAS_FATAL']) && $markers['HAS_FATAL'] === '1') $errors[] = 'Page contient "Fatal error"';
    if (empty($markers['HAS_DOCTYPE']) || $markers['HAS_DOCTYPE'] !== '1') $errors[] = 'DOCTYPE manquant';
    // Décoder la sortie pour vérifier le contenu
    $html = isset($markers['OUTPUT_BASE64']) ? base64_decode($markers['OUTPUT_BASE64']) : '';
    if ($html === '') $errors[] = 'Sortie HTML vide';
    if (strpos($html, '5.25.3') === false) $errors[] = 'Version 5.25.3 absente de la page';
    if (strpos($html, 'En résumé') === false) $errors[] = 'Section "En résumé" absente (S4-CHANGELOG non livré ?)';
    // Compter les versions rendues — chaque version a id="v-x.y.z" dans .version-card
    $version_count = preg_match_all('/id="v-\d+\.\d+\.\d+"/', $html);
    if ($version_count < 40) $errors[] = "Trop peu de versions rendues : $version_count (attendu >= 40)";
    return empty($errors) ? true : implode(' | ', $errors);
});

test('Runtime HTTP index.php (agent 0 soumission) — tutoriel visible ("Premiers pas")', function() {
    // S4-TUTORIAL (Action 6) : un agent avec 0 soumission ET 0 brouillon doit voir
    // le mini-tutoriel 3 étapes "Premiers pas en 3 étapes". On utilise un email
    // aléatoire jamais vu en DB pour garantir 0 soumission + 0 brouillon.
    $new_agent = 'new_agent_s4_tutorial_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    $markers = _run_http_subprocess(dirname(__DIR__) . '/index.php', $new_agent);
    $errors = [];
    if (!empty($markers['EXCEPTION'])) $errors[] = 'Exception : ' . $markers['EXCEPTION'];
    if (!empty($markers['HAS_FATAL']) && $markers['HAS_FATAL'] === '1') $errors[] = 'Page contient "Fatal error"';
    if (empty($markers['HAS_DOCTYPE']) || $markers['HAS_DOCTYPE'] !== '1') $errors[] = 'DOCTYPE manquant';
    $html = isset($markers['OUTPUT_BASE64']) ? base64_decode($markers['OUTPUT_BASE64']) : '';
    if ($html === '') $errors[] = 'Sortie HTML vide';
    // Le tutoriel contient "Premiers pas" (titre) ou "Tutoriel" (aria-label)
    $has_tutorial = strpos($html, 'Premiers pas') !== false || strpos($html, 'Tutoriel de prise en main') !== false;
    if (!$has_tutorial) $errors[] = 'Tutoriel S4-TUTORIAL non visible pour agent 0 soumission';
    return empty($errors) ? true : implode(' | ', $errors);
});

test('Runtime HTTP dashboard.php (admin) — "Actions avancées" visible (refonte S4-UI)', function() {
    // S4-UI / Action 2 (VÉTO 2 M. Robert) : le menu "Plus d'actions" caché dans
    // <details> a été remplacé par <div class="admin-actions-advanced"> visible.
    // La chaîne "Actions avancées" doit apparaître dans le HTML rendu du dashboard admin.
    $admin = get_admin_email();
    if (empty($admin)) return 'admin_email non configuré en DB test';
    $markers = _run_http_subprocess(dirname(__DIR__) . '/dashboard.php', $admin);
    $errors = [];
    if (!empty($markers['EXCEPTION'])) $errors[] = 'Exception : ' . $markers['EXCEPTION'];
    if (!empty($markers['HAS_FATAL']) && $markers['HAS_FATAL'] === '1') $errors[] = 'Page contient "Fatal error"';
    if (empty($markers['HAS_DOCTYPE']) || $markers['HAS_DOCTYPE'] !== '1') $errors[] = 'DOCTYPE manquant';
    $html = isset($markers['OUTPUT_BASE64']) ? base64_decode($markers['OUTPUT_BASE64']) : '';
    if ($html === '') $errors[] = 'Sortie HTML vide';
    if (strpos($html, 'Actions avancées') === false) $errors[] = 'Section "Actions avancées" absente (S4-UI non livré ?)';
    return empty($errors) ? true : implode(' | ', $errors);
});

echo "\n";
}
