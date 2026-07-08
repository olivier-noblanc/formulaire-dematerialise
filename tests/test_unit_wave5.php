<?php
/**
 * tests/test_unit_wave5.php — Section 13 : Wave 5 — R2-TESTER (Alertes + release_pdo + régression SQL)
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Section 13 : Wave 5 — R2-TESTER (Alertes + release_pdo + régression SQL)
 */
function run_tests_unit_wave5(): void {
echo "── 13. Tests Wave 5 — R2-TESTER (Alertes + release_pdo + régression SQL) ──\n";

// ── 13.1 Régression T-01 : aucun INSERT/SELECT utilise generate_uuid() en SQL ──
test('Régression T-01 : aucun generate_uuid() dans une requête SQL SQLite', function() {
    // Le bug T-01 (corrigé par R2-CTO) : generate_uuid() est une fonction PHP,
    // pas une fonction SQLite native. L'appeler dans VALUES(...), SELECT ou SET
    // lève "no such function: generate_uuid". Ce test blinde la non-régression.
    // On exclut les fichiers de test (test_*.php) qui légitimement mentionnent
    // le pattern dans leurs assertions/comments.
    $patterns = [
        '/VALUES\s*\([^)]*generate_uuid\s*\(\s*\)/i',
        '/SELECT\s+generate_uuid\s*\(\s*\)/i',
        '/SET\s+\w+\s*=\s*generate_uuid\s*\(\s*\)/i',
    ];
    $exclude_substrings = ['/PHPMailer/', '/vendor/', '/node_modules/', '/docs/screenshots/', '/.git/'];
    $violations = [];
    $root = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;
        $path = $file->getPathname();
        // Exclure les fichiers de test (ils mentionnent le pattern dans leurs assertions)
        if (strpos(basename($path), 'test_') === 0) continue;
        foreach ($exclude_substrings as $ex) {
            if (strpos($path, $ex) !== false) continue 2;
        }
        $content = @file_get_contents($path);
        if ($content === false) continue;
        foreach ($patterns as $pat) {
            if (preg_match($pat, $content, $m, PREG_OFFSET_CAPTURE)) {
                $offset = $m[0][1];
                $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                $rel = substr($path, strlen($root) + 1);
                $violations[] = $rel . ':' . $line . ' → "' . trim(substr($content, $offset, 80)) . '"';
            }
        }
    }
    return empty($violations) ? true : 'Occurrences interdites : ' . implode(' | ', $violations);
});

// ── 13.2 admin_alerts.php POST add_rule : UUID bindé côté PHP (T-01) ──
test('admin_alerts.php POST add_rule : crée une règle avec UUID valide (T-01)', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    // Réplique exacte de admin_alerts.php:43-47 (post-fix R2-CTO) :
    // UUID généré côté PHP puis bindé en 1er paramètre (pas de generate_uuid() en SQL).
    $rule_id = generate_uuid();
    $label = 'Test add_rule R2-TESTER ' . bin2hex(random_bytes(4));
    try {
        $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)")
            ->execute([$rule_id, $onb_id, 7, 'steps_incomplete', 'admin', $label]);
    } catch (Throwable $e) {
        return 'INSERT a échoué (régression T-01 ?) : ' . $e->getMessage();
    }
    $stmt = $pdo->prepare("SELECT id, form_id, days_before, notify_who, label, actif FROM alert_rules WHERE id = ?");
    $stmt->execute([$rule_id]);
    $created = $stmt->fetch(PDO::FETCH_ASSOC);
    $errors = [];
    if (!$created) $errors[] = 'Règle non trouvée en DB après INSERT';
    else {
        if ($created['id'] !== $rule_id) $errors[] = 'UUID mismatch : attendu=' . $rule_id . ', obtenu=' . $created['id'];
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $created['id'])) {
            $errors[] = 'UUID invalide (pas v4) : ' . $created['id'];
        }
        if ($created['label'] !== $label) $errors[] = 'Label mismatch';
        if ((int)$created['days_before'] !== 7) $errors[] = 'days_before mismatch';
        if ((int)$created['actif'] !== 1) $errors[] = 'actif mismatch (attendu 1)';
    }
    $pdo->prepare("DELETE FROM alert_rules WHERE id = ?")->execute([$rule_id]);
    return empty($errors) ? true : implode(' | ', $errors);
});

// ── 13.3 alert_check.php CLI : alerte J-3 envoyée, loggée, dédoublonnée (T-01/P-01/O-02) ──
test('alert_check.php CLI : alerte J-3 envoyée et loggée avec UUID (T-01/P-01/O-02)', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    // Setup : règle J-3 avec email custom unique (isole des autres règles seeded)
    $rule_id = generate_uuid();
    $test_email = 'alert_r2_tester_' . bin2hex(random_bytes(4)) . '@exemple.invalid';
    $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, 3, 'steps_incomplete', ?, ?, 1)")
        ->execute([$rule_id, $onb_id, $test_email, 'Test R2-TESTER alert_check J-3']);
    // Soumission en cours avec deadline à J+3 (fenêtre J-3 = aujourd'hui)
    $sub_id = generate_uuid();
    $deadline = (new DateTimeImmutable('+3 days'))->format('Y-m-d');
    $data = json_encode(['nom' => 'R2Tester', 'prenom' => 'Alert', 'date_prise_poste' => $deadline]);
    $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
        ->execute([$sub_id, $onb_id, $data, 'test@exemple.invalid']);
    // Nettoyer tout alert_log préexistant pour cette règle (au cas où)
    $pdo->prepare("DELETE FROM alert_log WHERE rule_id = ?")->execute([$rule_id]);
    // Construire la commande PHP subprocess :
    // - réutilise binaire + ini courants (extensions chargées)
    // - -d session.save_path pour éviter l'échec session_start() en sandbox
    // - env APP_TEST_MODE=1 pour activer TEST_MODE (utilisation de la DB test)
    //   ⚠ Sans APP_TEST_MODE, alert_check.php utiliserait la DB de prod
    //   (workflow.db) qui a schema_version=8 → déclencherait la migration v9 → crash.
    $session_dir = sys_get_temp_dir() . '/php-sessions';
    @mkdir($session_dir, 0777, true);
    $ini = php_ini_loaded_file();
    $php_cmd = PHP_BINARY
        . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -d session.save_path=' . escapeshellarg($session_dir);
    $alert_check = escapeshellarg(dirname(__DIR__) . '/alert_check.php');
    $env = 'APP_TEST_MODE=1';
    // ⚠ Libérer la connexion PDO du parent avant de lancer le subprocess :
    // en mode WAL, les readers ne bloquent normalement pas les writers, mais
    // si le parent a une statement active ou un lock résiduel, le subprocess
    // peut obtenir "database table is locked" (SQLITE_LOCKED) sur ses writes
    // (INSERT alert_log, set_setting, app_log) → alerte non envoyée.
    // release_pdo() ferme proprement la connexion et libère tous les locks.
    release_pdo();
    // 1ère exécution
    $out1 = shell_exec("env $env $php_cmd $alert_check 2>&1");
    // Reconnecter pour vérifier les résultats
    $pdo = \App\Core\App::db()->getPdo();
    $errors = [];
    // Vérif 1 : pas de régression T-01 ("no such function: generate_uuid")
    if (strpos($out1 ?? '', 'no such function: generate_uuid') !== false) {
        $errors[] = 'RÉGRESSION T-01 : generate_uuid() appelé en SQL';
    }
    // Vérif 2 : pas d'erreur fatale
    if (preg_match('/(Fatal error|Parse error|Uncaught|Migration v9 FAILED)/', $out1 ?? '')) {
        $errors[] = 'Erreur fatale : ' . substr($out1, 0, 300);
    }
    // Vérif 3 : 1 entrée alert_log créée avec UUID v4 valide
    $log_count_1 = (int)$pdo->query('SELECT COUNT(*) FROM alert_log WHERE rule_id = ' . $pdo->quote($rule_id))->fetchColumn();
    if ($log_count_1 < 1) $errors[] = 'Aucune entrée alert_log créée (1ère exécution). Output : ' . substr($out1 ?? '', 0, 300);
    else {
        $log_id = $pdo->query('SELECT id FROM alert_log WHERE rule_id = ' . $pdo->quote($rule_id) . ' LIMIT 1')->fetchColumn();
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $log_id)) {
            $errors[] = 'alert_log.id n\'est pas un UUID v4 : ' . $log_id;
        }
    }
    // Vérif 4 : stdout confirme l'envoi vers $test_email (unique par test)
    if (strpos($out1 ?? '', 'Alerte J-3') === false || strpos($out1 ?? '', $test_email) === false) {
        $errors[] = 'Stdout ne confirme pas l\'envoi. Output : ' . substr($out1 ?? '', 0, 300);
    }
    // 2e exécution : déduplication (pas de doublon en alert_log)
    release_pdo();
    $out2 = shell_exec("env $env $php_cmd $alert_check 2>&1");
    $pdo = \App\Core\App::db()->getPdo();
    $log_count_2 = (int)$pdo->query('SELECT COUNT(*) FROM alert_log WHERE rule_id = ' . $pdo->quote($rule_id))->fetchColumn();
    if ($log_count_2 !== $log_count_1) {
        $errors[] = "Déduplication cassée : alert_log count $log_count_1 → $log_count_2 après 2e exécution";
    }
    // Cleanup
    $pdo->prepare("DELETE FROM alert_log WHERE rule_id = ?")->execute([$rule_id]);
    $pdo->prepare("DELETE FROM alert_rules WHERE id = ?")->execute([$rule_id]);
    $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
    return empty($errors) ? true : implode(' | ', $errors);
});

// ── 13.4 release_pdo() (T-19/O-05) ──
test('release_pdo() existe et retourne void (T-19)', function() {
    if (!function_exists('release_pdo')) return 'release_pdo() n\'existe pas';
    $result = release_pdo();
    return $result === null ? true : 'Attendu null/void, obtenu : ' . var_export($result, true);
});

test('release_pdo() préserve le singleton avant release (T-19)', function() {
    $pdo1 = \App\Core\App::db()->getPdo();
    $pdo2 = \App\Core\App::db()->getPdo();
    return ($pdo1 === $pdo2) ? true : 'Singleton cassé : get_pdo() retourne des instances différentes';
});

test('release_pdo() : get_pdo() retourne une nouvelle instance après release (T-19)', function() {
    $pdo1 = \App\Core\App::db()->getPdo();
    release_pdo();
    $pdo2 = \App\Core\App::db()->getPdo();
    return ($pdo1 !== $pdo2) ? true : 'get_pdo() retourne la même instance après release_pdo()';
});

test('release_pdo() met $GLOBALS[_pdo_test] à null (T-19)', function() {
    \App\Core\App::db()->getPdo();
    release_pdo();
    return (!isset($GLOBALS['_pdo_test']) || $GLOBALS['_pdo_test'] === null) ? true : "\$GLOBALS['_pdo_test'] non null après release_pdo()";
});

test('release_pdo() idempotente : 2 appels successifs ne lèvent pas d\'erreur (T-19)', function() {
    \App\Core\App::db()->getPdo();
    try {
        release_pdo();
        release_pdo(); // 2e appel : $GLOBALS['_pdo_test'] déjà null
        return true;
    } catch (Throwable $e) {
        return 'Erreur levée : ' . $e->getMessage();
    }
});

test('release_pdo() rollback une transaction en cours (T-19)', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $marker = 'rollback_r2_tester_' . bin2hex(random_bytes(4));
    $rule_id = generate_uuid();
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, 99, 'steps_incomplete', 'admin', ?, 1)")
        ->execute([$rule_id, $onb_id, $marker]);
    // Vérifier que la ligne est visible DANS la transaction
    $in_txn = (int)$pdo->query('SELECT COUNT(*) FROM alert_rules WHERE label = ' . $pdo->quote($marker))->fetchColumn();
    if ($in_txn !== 1) {
        try { $pdo->rollBack(); } catch (Throwable $e) {}
        return 'Ligne non visible dans la transaction';
    }
    // release_pdo() doit rollbacker la transaction et libérer la connexion
    release_pdo();
    // Rouvrir une connexion et vérifier que la ligne a été rollbackée
    $pdo2 = \App\Core\App::db()->getPdo();
    $after = (int)$pdo2->query('SELECT COUNT(*) FROM alert_rules WHERE label = ' . $pdo2->quote($marker))->fetchColumn();
    // Cleanup (au cas où le rollback n'aurait pas marché)
    $pdo2->prepare("DELETE FROM alert_rules WHERE label = ?")->execute([$marker]);
    return $after === 0 ? true : "Transaction non rollbackée : $after ligne(s) toujours présentes après release_pdo()";
});

echo "\n";
}
