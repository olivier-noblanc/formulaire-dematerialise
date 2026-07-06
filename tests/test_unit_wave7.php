<?php
/**
 * tests/test_unit_wave7.php — Section 15 : Wave 7 — S3-TESTER (submission_view.php E2E + anti-régression bug v3.1.0)
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Section 15 : Wave 7 — S3-TESTER (submission_view.php E2E + anti-régression bug v3.1.0)
 */
function run_tests_unit_wave7(): void {
echo "── 15. Tests Wave 7 — S3-TESTER (submission_view.php E2E + anti-régression bug v3.1.0) ──\n";

// ── 15.0 Setup : la table delegations a une colonne token_id (pas from_token_id) ──
// Test positif qui documente la découverte clé du S3-TESTER : la colonne réelle est `token_id`.
test('Setup : la table delegations a une colonne token_id (pas from_token_id)', function() {
    $pdo = get_pdo();
    $cols = $pdo->query("PRAGMA table_info(delegations)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $has_token_id = in_array('token_id', $cols, true);
    $has_from_token_id = in_array('from_token_id', $cols, true);
    if (!$has_token_id) return 'Colonne token_id manquante dans delegations. Colonnes: ' . implode(',', $cols);
    if ($has_from_token_id) return 'Colonne from_token_id inattendue présente dans delegations';
    return true;
});

// ── 15.1 get_delegations() retourne un tableau vide pour une soumission sans délégation ──
test('get_delegations() retourne un tableau vide pour une soumission sans délégation', function() {
    // Cas nominal : une soumission sans aucune délégation doit retourner [].
    // ⚠ Ce test ÉCHOUE actuellement à cause du bug S2-TESTER (d.from_token_id inexistant).
    //   La fonction lève "no such column: d.from_token_id" AVANT même de filtrer les rows,
    //   car la colonne est résolue au prepare/execute du JOIN.
    //   TODO S4 : remplacer `d.from_token_id` par `d.token_id` (colonne réelle) dans helpers.php:3034.
    $pdo = get_pdo();
    // Récupérer une soumission qui existe (sans délégation connue)
    $sub_id = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();
    if (!$sub_id) return 'Aucune soumission en DB test pour le test';
    try {
        $result = get_delegations($sub_id);
        return is_array($result) && empty($result)
            ? true
            : 'Attendu tableau vide, obtenu : ' . substr(json_encode($result), 0, 200);
    } catch (\Throwable $e) {
        // Bug S2-TESTER : la fonction lève PDOException au lieu de retourner []
        return 'BUG S2-TESTER — get_delegations() a levé une exception au lieu de retourner [] : '
            . $e->getMessage()
            . ' | TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034';
    }
});

// ── 15.2 get_delegations() retourne les délégations correctes (count, colonnes, ordre) ──
test('get_delegations() retourne les délégations correctes pour une soumission avec délégations', function() {
    // Cas avec délégations : vérifier count, colonnes attendues, ordre delegated_at DESC.
    // ⚠ Ce test ÉCHOUE actuellement à cause du bug S2-TESTER (d.from_token_id inexistant).
    //   TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034.
    //   Note : la spec listait `from_token_id` dans les colonnes attendues, mais le schéma
    //   réel a `token_id` — cette assertion sera satisfaite quand le bug sera résolu.
    $pdo = get_pdo();
    // Créer une soumission + délégation pour vérifier count, colonnes, ordre
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $sub_id = generate_uuid();
    $user = 's3_tester_deleg_' . bin2hex(random_bytes(4)) . '@exemple.invalid';
    $data = json_encode(['nom' => 'S3TEST', 'prenom' => 'Deleg']);
    try {
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onb_id, $data, $user]);
        advance_workflow($sub_id);
        // Récupérer un token pending (étape 1 a normalement 1+ validateur)
        $stmt = $pdo->prepare("SELECT id FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $tok_id = $stmt->fetchColumn();
        if (!$tok_id) return 'Pas de token pending pour le test';
        // Créer une délégation via delegate_token() (insert dans `token_id` — colonne réelle)
        $delegate_email = 's3_deleg_target_' . bin2hex(random_bytes(4)) . '@exemple.invalid';
        $r = delegate_token($tok_id, $delegate_email, 'Test delegation S3-TESTER');
        if (empty($r['success'])) return 'delegate_token a échoué : ' . ($r['message'] ?? '');
        // Appeler get_delegations() — doit lever à cause du bug S2-TESTER
        try {
            $delegations = get_delegations($sub_id);
        } catch (\Throwable $e) {
            return 'BUG S2-TESTER — get_delegations() a levé : ' . $e->getMessage()
                . ' | TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034';
        }
        $errors = [];
        if (count($delegations) < 1) $errors[] = 'Attendu ≥1 délégation, obtenu ' . count($delegations);
        if (!empty($delegations)) {
            // Colonnes attendues : step_id, step_label (du JOIN), token_id/from_email/to_email/
            // reason/delegated_at (delegations.*, le schéma réel a `token_id` pas `from_token_id`)
            $expected_cols = ['step_id', 'step_label', 'token_id', 'from_email', 'to_email', 'reason', 'delegated_at'];
            $actual_cols = array_keys($delegations[0]);
            foreach ($expected_cols as $c) {
                if (!in_array($c, $actual_cols, true)) {
                    $errors[] = "Colonne manquante : $c (colonnes réelles : " . implode(',', $actual_cols) . ')';
                }
            }
            // Vérifier l'ordre delegated_at DESC (avec 1 seule délégation, le tri est trivial)
            if (count($delegations) > 1) {
                $dates = array_column($delegations, 'delegated_at');
                $sorted = $dates;
                rsort($sorted);
                if ($dates !== $sorted) $errors[] = 'Ordre delegated_at DESC non respecté';
            }
        }
        return empty($errors) ? true : implode(' | ', $errors);
    } finally {
        // Cleanup (token_id est la colonne réelle — pas from_token_id)
        $pdo->exec("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = " . $pdo->quote($sub_id) . ")");
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
    }
});

// ── 15.3 Anti-régression (inspection source) : get_delegations() utilise d.token_id (colonne réelle) ──
test('Anti-régression bug v3.1.0 : get_delegations() utilise d.token_id (colonne réelle) dans son SQL', function() {
    // Test anti-régression : vérifie que le code source de get_delegations() utilise bien
    // `d.token_id` (la colonne RÉELLE de la table delegations — cf. DatabaseMigrations.php:134
    // et PRAGMA table_info). Historique : S2-TESTER avait introduit par erreur `d.from_token_id`
    // (colonne inexistante), corrigé en S3. Ce test garantit qu'on ne revient pas à cette erreur.
    // S4-TESTS / Action 9 : _find_function_in_libs() parcourt helpers.php + lib_*.php pour
    // rester robuste si get_delegations() est extraite vers lib_*.php un jour.
    $body = _find_function_in_libs('get_delegations');
    if ($body === '') return 'Fonction get_delegations() introuvable dans helpers.php + lib_*.php';
    $has_token_id = (bool)preg_match('/\bd\.token_id\b/', $body);
    $has_from_token_id = strpos($body, 'd.from_token_id') !== false;
    if (!$has_token_id) {
        return '`d.token_id` (colonne réelle) absent du SQL de get_delegations()';
    }
    if ($has_from_token_id) {
        return 'Régression S2-TESTER détectée : `d.from_token_id` (colonne inexistante) est présent dans get_delegations()';
    }
    return true;
});

// ── 15.4 submission_view.php rend une page 200 OK pour un ID valide (smoke test HTTP) ──
test('submission_view.php rend une page 200 OK pour un ID de soumission valide (admin)', function() {
    // Smoke test HTTP : un admin accédant à une soumission valide doit voir la page complète.
    // Vérifie la présence des éléments clés : titre, statut, timeline workflow, boutons d'action.
    // ⚠ Ce test ÉCHOUE actuellement à cause du bug S2-TESTER — submission_view.php:555 appelle
    //   get_delegations() qui lève une PDOException, crashant la page (HTTP 500 implicite).
    //   TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034.
    $pdo = get_pdo();
    $sub_id = $pdo->query("SELECT id FROM submissions LIMIT 1")->fetchColumn();
    if (!$sub_id) return 'Aucune soumission en DB test pour le test';
    // Construire le script subprocess
    $session_dir = sys_get_temp_dir() . '/php-sessions';
    @mkdir($session_dir, 0777, true);
    $ini = php_ini_loaded_file();
    $php_cmd = PHP_BINARY
        . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -d session.save_path=' . escapeshellarg($session_dir);
    $submission_view_path = escapeshellarg(dirname(__DIR__) . '/submission_view.php');
    $script = sys_get_temp_dir() . '/test_sv_' . uniqid() . '.php';
    $code = <<<PHP
<?php
\$_SERVER['HTTP_X_TEST_MODE'] = '1';
\$_SERVER['HTTP_X_TEST_USER'] = 'admin@exemple.invalid';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['HTTPS'] = '';
\$_SERVER['REQUEST_URI'] = '/submission_view.php?id=' . urlencode('{$sub_id}');
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SCRIPT_NAME'] = 'health.php';
\$_SERVER['SCRIPT_FILENAME'] = 'health.php';
\$_GET['id'] = '{$sub_id}';
error_reporting(E_ALL);
ini_set('display_errors', 1);
ob_start();
try {
    require {$submission_view_path};
    \$out = ob_get_clean();
    echo 'OUTPUT_LEN=' . strlen(\$out) . "\n";
    echo 'HAS_TITLE=' . (strpos(\$out, 'Soumission #') !== false ? '1' : '0') . "\n";
    echo 'HAS_WORKFLOW=' . (strpos(\$out, 'Circuit de validation') !== false ? '1' : '0') . "\n";
    echo 'HAS_DATA=' . (strpos(\$out, 'Données du formulaire') !== false ? '1' : '0') . "\n";
    echo 'HAS_ACTION_BTN=' . (strpos(\$out, 'btn-danger') !== false ? '1' : '0') . "\n";
} catch (\Throwable \$e) {
    ob_end_clean();
    echo 'EXCEPTION=' . str_replace(["\n", "\r"], ' ', \$e->getMessage()) . "\n";
    echo 'EXCEPTION_FILE=' . basename(\$e->getFile()) . ':' . \$e->getLine() . "\n";
}
PHP;
    file_put_contents($script, $code);
    $env = 'APP_TEST_MODE=1 APP_TEST_SECRET=test';
    $output = shell_exec("env $env $php_cmd " . escapeshellarg($script) . " 2>&1");
    @unlink($script);
    // Parser les marqueurs (lignes "KEY=VALUE")
    $markers = [];
    foreach (explode("\n", $output ?? '') as $line) {
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $markers[$k] = $v;
    }
    // Vérifier d'abord si une exception a été levée (bug S2-TESTER)
    if (isset($markers['EXCEPTION'])) {
        return 'BUG S2-TESTER — submission_view.php a crashé : ' . $markers['EXCEPTION']
            . ' (à ' . ($markers['EXCEPTION_FILE'] ?? '?') . ')'
            . ' | TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034';
    }
    $errors = [];
    if (empty($markers['HAS_TITLE']) || $markers['HAS_TITLE'] !== '1') $errors[] = 'Titre "Soumission #" manquant';
    if (empty($markers['HAS_WORKFLOW']) || $markers['HAS_WORKFLOW'] !== '1') $errors[] = 'Timeline workflow manquante';
    if (empty($markers['HAS_DATA']) || $markers['HAS_DATA'] !== '1') $errors[] = 'Section "Données du formulaire" manquante';
    if (empty($markers['HAS_ACTION_BTN']) || $markers['HAS_ACTION_BTN'] !== '1') $errors[] = 'Boutons d\'action manquants';
    return empty($errors) ? true : implode(' | ', $errors);
});

// ── 15.5 submission_view.php gère un ID invalide (404 propre, pas de crash 500) ──
test('submission_view.php gère un ID invalide (404 propre, pas de crash 500)', function() {
    // Cas ID invalide : la page doit appeler render_error_page(404, ...) — qui exit(1) en CLI.
    // Le check "if (!$sub)" ligne 23-27 intervient AVANT la ligne 555 (get_delegations),
    // donc ce test n'est PAS impacté par le bug S2-TESTER.
    $session_dir = sys_get_temp_dir() . '/php-sessions';
    @mkdir($session_dir, 0777, true);
    $ini = php_ini_loaded_file();
    $php_cmd = PHP_BINARY
        . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -d session.save_path=' . escapeshellarg($session_dir);
    $submission_view_path = escapeshellarg(dirname(__DIR__) . '/submission_view.php');
    $script = sys_get_temp_dir() . '/test_sv_404_' . uniqid() . '.php';
    $invalid_id = 'nonexistent-uuid-12345678-1234-4321-8765-123456789012';
    $code = <<<PHP
<?php
\$_SERVER['HTTP_X_TEST_MODE'] = '1';
\$_SERVER['HTTP_X_TEST_USER'] = 'admin@exemple.invalid';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['HTTPS'] = '';
\$_SERVER['REQUEST_URI'] = '/submission_view.php?id={$invalid_id}';
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SCRIPT_NAME'] = 'health.php';
\$_SERVER['SCRIPT_FILENAME'] = 'health.php';
\$_GET['id'] = '{$invalid_id}';
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 1);
ob_start();
register_shutdown_function(function() {
    \$out = ob_get_clean();
    echo 'SHUTDOWN_OUTPUT_LEN=' . strlen(\$out) . "\n";
    echo 'HAS_404=' . (strpos(\$out, '404') !== false ? '1' : '0') . "\n";
    echo 'HAS_INTR=' . (strpos(\$out, 'introuvable') !== false ? '1' : '0') . "\n";
    echo 'HAS_DOCTYPE=' . (strpos(\$out, 'DOCTYPE') !== false ? '1' : '0') . "\n";
    echo 'HAS_FATAL=' . (strpos(\$out, 'Fatal error') !== false ? '1' : '0') . "\n";
    echo 'HAS_PDOEXCEPTION=' . (strpos(\$out, 'PDOException') !== false ? '1' : '0') . "\n";
    echo 'HAS_NO_SUCH_COLUMN=' . (strpos(\$out, 'no such column') !== false ? '1' : '0') . "\n";
});
try {
    require {$submission_view_path};
} catch (\Throwable \$e) {
    echo 'EXCEPTION=' . str_replace(["\n", "\r"], ' ', \$e->getMessage()) . "\n";
    echo 'EXCEPTION_CLASS=' . get_class(\$e) . "\n";
}
PHP;
    file_put_contents($script, $code);
    $env = 'APP_TEST_MODE=1 APP_TEST_SECRET=test';
    $output = shell_exec("env $env $php_cmd " . escapeshellarg($script) . " 2>&1");
    @unlink($script);
    $markers = [];
    foreach (explode("\n", $output ?? '') as $line) {
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $markers[$k] = $v;
    }
    $errors = [];
    // render_error_page en CLI fait exit(1) — l'output contient la page 404 HTML
    if (!empty($markers['HAS_FATAL']) && $markers['HAS_FATAL'] === '1') $errors[] = 'Page contient "Fatal error"';
    if (!empty($markers['HAS_NO_SUCH_COLUMN']) && $markers['HAS_NO_SUCH_COLUMN'] === '1') {
        $errors[] = 'BUG S2-TESTER — page a crashé sur get_delegations() (no such column)';
    }
    // Vérifier la présence de marqueurs 404
    $has_404 = !empty($markers['HAS_404']) && $markers['HAS_404'] === '1';
    $has_introuvable = !empty($markers['HAS_INTR']) && $markers['HAS_INTR'] === '1';
    if (!$has_404 && !$has_introuvable) {
        $errors[] = 'Page ne contient ni "404" ni "introuvable" (output: ' . substr($output ?? '', 0, 200) . ')';
    }
    return empty($errors) ? true : implode(' | ', $errors);
});

// ── 15.6 submission_view.php gère un ID valide d'un autre utilisateur (pas de fuite d'info) ──
test('submission_view.php gère un ID valide d\'un autre utilisateur (redirect propre, pas de fuite)', function() {
    // Cas accès non autorisé : un utilisateur non-admin accédant à la soumission d'un autre
    // doit être redirigé vers dashboard.php (soumission_view.php:40-42) — pas de 403 explicite,
    // mais pas de fuite d'info non plus. Le check ligne 35-43 intervient AVANT la ligne 555
    // (get_delegations), donc ce test n'est PAS impacté par le bug S2-TESTER.
    // Note spec/impl : la spec mentionnait "rend 403" — l'implémentation actuelle fait un
    // redirect 302 vers dashboard.php. Ce test valide le comportement réel (302 + pas de fuite).
    $pdo = get_pdo();
    // Récupérer une soumission dont le submitted_by est connu
    $row = $pdo->query("SELECT id, submitted_by FROM submissions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'Aucune soumission en DB test pour le test';
    $sub_id = $row['id'];
    $owner = $row['submitted_by'];
    // Utilisateur "autre" : un email qui n'est ni admin, ni le owner, ni validateur
    $other_user = 'other_user_s3_tester_' . bin2hex(random_bytes(4)) . '@exemple.invalid';
    $session_dir = sys_get_temp_dir() . '/php-sessions';
    @mkdir($session_dir, 0777, true);
    $ini = php_ini_loaded_file();
    $php_cmd = PHP_BINARY
        . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -d session.save_path=' . escapeshellarg($session_dir);
    $submission_view_path = escapeshellarg(dirname(__DIR__) . '/submission_view.php');
    $script = sys_get_temp_dir() . '/test_sv_403_' . uniqid() . '.php';
    $code = <<<PHP
<?php
\$_SERVER['HTTP_X_TEST_MODE'] = '1';
\$_SERVER['HTTP_X_TEST_USER'] = '{$other_user}';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['HTTPS'] = '';
\$_SERVER['REQUEST_URI'] = '/submission_view.php?id=' . urlencode('{$sub_id}');
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SCRIPT_NAME'] = 'health.php';
\$_SERVER['SCRIPT_FILENAME'] = 'health.php';
\$_GET['id'] = '{$sub_id}';
error_reporting(E_ALL & ~E_WARNING);
ini_set('display_errors', 1);
ob_start();
register_shutdown_function(function() {
    \$out = ob_get_clean();
    echo 'SHUTDOWN_OUTPUT_LEN=' . strlen(\$out) . "\n";
    echo 'HAS_OWNER_EMAIL=' . (strpos(\$out, '{$owner}') !== false ? '1' : '0') . "\n";
    echo 'HAS_SUBMISSION_DATA=' . (strpos(\$out, 'Données du formulaire') !== false ? '1' : '0') . "\n";
    echo 'HAS_FATAL=' . (strpos(\$out, 'Fatal error') !== false ? '1' : '0') . "\n";
    echo 'HAS_PDOEXCEPTION=' . (strpos(\$out, 'PDOException') !== false ? '1' : '0') . "\n";
    echo 'HTTP_RESPONSE_CODE=' . http_response_code() . "\n";
});
try {
    require {$submission_view_path};
} catch (\Throwable \$e) {
    echo 'EXCEPTION=' . str_replace(["\n", "\r"], ' ', \$e->getMessage()) . "\n";
}
PHP;
    file_put_contents($script, $code);
    $env = 'APP_TEST_MODE=1 APP_TEST_SECRET=test';
    $output = shell_exec("env $env $php_cmd " . escapeshellarg($script) . " 2>&1");
    @unlink($script);
    $markers = [];
    foreach (explode("\n", $output ?? '') as $line) {
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $markers[$k] = $v;
    }
    $errors = [];
    if (!empty($markers['HAS_FATAL']) && $markers['HAS_FATAL'] === '1') $errors[] = 'Page contient "Fatal error"';
    if (!empty($markers['HAS_PDOEXCEPTION']) && $markers['HAS_PDOEXCEPTION'] === '1') {
        $errors[] = 'BUG S2-TESTER — page a crashé sur get_delegations() (PDOException)';
    }
    // Vérifier qu'il y a un redirect 302 (header('Location: dashboard.php') + exit)
    $http_code = $markers['HTTP_RESPONSE_CODE'] ?? '0';
    if ($http_code !== '302') {
        $errors[] = "Pas de redirect 302 (http_response_code=$http_code)";
    }
    // Vérifier qu'aucune info de la soumission n'a fuité
    if (!empty($markers['HAS_OWNER_EMAIL']) && $markers['HAS_OWNER_EMAIL'] === '1') {
        $errors[] = 'Fuite d\'info : l\'email du propriétaire apparaît dans la sortie';
    }
    if (!empty($markers['HAS_SUBMISSION_DATA']) && $markers['HAS_SUBMISSION_DATA'] === '1') {
        $errors[] = 'Fuite d\'info : la section "Données du formulaire" est visible';
    }
    return empty($errors) ? true : implode(' | ', $errors);
});

// ── 15.7 Runtime anti-régression : crée une délégation factice et vérifie qu'elle est retournée ──
test('Runtime anti-régression : get_delegations() retourne une délégation factice créée via delegate_token()', function() {
    // Variante runtime du Test 3 (per spec, "Soit via inspection du code source (grep), soit via runtime").
    // Crée une délégation via delegate_token() (qui insère dans `token_id` — colonne réelle),
    // puis appelle get_delegations() (qui JOIN sur `from_token_id` — colonne inexistante).
    // ⚠ Ce test ÉCHOUE à cause du bug S2-TESTER. Il démontre explicitement la régression.
    //   TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034.
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $sub_id = generate_uuid();
    $user = 's3_tester_runtime_' . bin2hex(random_bytes(4)) . '@exemple.invalid';
    $delegate_email = 's3_deleg_runtime_' . bin2hex(random_bytes(4)) . '@exemple.invalid';
    $data = json_encode(['nom' => 'Runtime', 'prenom' => 'Test']);
    try {
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onb_id, $data, $user]);
        advance_workflow($sub_id);
        $stmt = $pdo->prepare("SELECT id FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $tok_id = $stmt->fetchColumn();
        if (!$tok_id) return 'Pas de token pending pour le test';
        $r = delegate_token($tok_id, $delegate_email, 'Test runtime S3-TESTER');
        if (empty($r['success'])) return 'delegate_token() a échoué : ' . ($r['message'] ?? '');
        // Vérifier que la délégation est bien en DB (lecture directe — colonne `token_id`)
        $count_db = (int)$pdo->query("SELECT COUNT(*) FROM delegations WHERE token_id = " . $pdo->quote($tok_id))->fetchColumn();
        if ($count_db !== 1) return 'Délégation non insérée en DB (count=' . $count_db . ')';
        // Maintenant appeler get_delegations() — doit retourner la délégation
        try {
            $delegations = get_delegations($sub_id);
        } catch (\Throwable $e) {
            return 'BUG S2-TESTER — get_delegations() lève alors que la délégation existe en DB : '
                . $e->getMessage()
                . ' | TODO S4 : remplacer `d.from_token_id` par `d.token_id` dans helpers.php:3034';
        }
        if (count($delegations) !== 1) {
            return 'Attendu 1 délégation, obtenu ' . count($delegations);
        }
        if ($delegations[0]['to_email'] !== $delegate_email) {
            return 'to_email mismatch : attendu ' . $delegate_email . ', obtenu ' . $delegations[0]['to_email'];
        }
        return true;
    } finally {
        // Cleanup (token_id est la colonne réelle — pas from_token_id)
        $pdo->exec("DELETE FROM delegations WHERE token_id IN (SELECT id FROM tokens WHERE submission_id = " . $pdo->quote($sub_id) . ")");
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
    }
});

echo "\n";
}
