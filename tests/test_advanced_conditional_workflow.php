<?php
/**
 * tests/test_advanced_conditional_workflow.php — Section 9 : Branches conditionnelles (v19).
 *
 * Teste evaluate_step_condition() et le filtrage par condition dans
 * advance_workflow(). Couvre les cas :
 *  - Étape sans condition → s'exécute (rétrocompat)
 *  - Étape avec condition `equals` vraie → s'exécute
 *  - Étape avec condition `equals` fausse → skippée
 *  - Toutes les étapes d'un ordre skippées → avance à l'ordre suivant
 *  - Plus aucun ordre avec étapes à exécuter → clôture (status='valide')
 *  - Opérateurs not_equals / contains / not_empty / empty
 *  - Migration v19 (colonne `condition` sur steps)
 *  - Export / Import JSON avec condition
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo.
 *
 * @package tests
 */

declare(strict_types=1);

/**
 * Helper local : crée un formulaire + étapes + destinataires + champ validateur
 * pour les tests de branches conditionnelles. Retourne le form_id créé.
 *
 * @param PDO $pdo
 * @return string form_id créé
 */
function _create_conditional_test_form(PDO $pdo): string {
    $form_id = generate_uuid();
    $slug = 'test_cond_v19_' . substr(generate_uuid(), 0, 8);
    $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
        ->execute([$form_id, $slug, 'Test Conditionnel v19']);

    // Champ validateur `decision_sg` (filled_by='validator', visible à l'étape 1)
    $field_id = generate_uuid();
    $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, '', 0, 1, 'Décision', 'validator', '', 'all')")
        ->execute([
            $field_id, $form_id,
            'Décision SG', 'select', 'decision_sg',
            json_encode(['Acceptée', 'Refusée', 'En attente'], JSON_UNESCAPED_UNICODE),
        ]);

    // Étape 1 — owner (remplit decision_sg)
    $step1_id = generate_uuid();
    $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 1, 1, '')")
        ->execute([$step1_id, $form_id, 'Étape 1 — Décision SG']);
    $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
        ->execute([generate_uuid(), $step1_id, 'owner@e2e.test']);

    // Étape 2 — Logistique (condition: decision_sg equals "Acceptée")
    $step2_id = generate_uuid();
    $cond2 = json_encode(['field' => 'decision_sg', 'op' => 'equals', 'value' => 'Acceptée'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 2, 1, ?)")
        ->execute([$step2_id, $form_id, 'Étape 2 — Logistique', $cond2]);
    $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
        ->execute([generate_uuid(), $step2_id, 'logistique@e2e.test']);

    // Étape 3 — DSI (même condition, parallèle à l'étape 2)
    $step3_id = generate_uuid();
    $cond3 = json_encode(['field' => 'decision_sg', 'op' => 'equals', 'value' => 'Acceptée'], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 2, 1, ?)")
        ->execute([$step3_id, $form_id, 'Étape 3 — DSI', $cond3]);
    $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
        ->execute([generate_uuid(), $step3_id, 'dsi@e2e.test']);

    return $form_id;
}

/**
 * Helper local : nettoie le formulaire de test et toutes ses dépendances.
 */
function _cleanup_conditional_test_form(PDO $pdo, string $form_id): void {
    // Supprimer les tokens des soumissions de ce formulaire
    $pdo->exec("DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = '" . $form_id . "')");
    $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = '" . $form_id . "')");
    $pdo->exec("DELETE FROM submissions WHERE form_id = '" . $form_id . "'");
    $pdo->exec("DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = '" . $form_id . "')");
    $pdo->exec("DELETE FROM steps WHERE form_id = '" . $form_id . "'");
    $pdo->exec("DELETE FROM form_fields WHERE form_id = '" . $form_id . "'");
    $pdo->exec("DELETE FROM forms WHERE id = '" . $form_id . "'");
}

/**
 * Helper local : valide un token (cherche par submission_id + step_id) et
 * l'effectue via validate_token(). Retourne true si validé.
 */
function _validate_first_pending_token(PDO $pdo, string $submission_id): bool {
    $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
    $stmt->execute([$submission_id]);
    $token = $stmt->fetchColumn();
    if (!$token) return false;
    $result = \App\Core\App::workflow()->validateToken((string)$token, 'valider', 'Validation conditionnelle test');
    return ($result['status'] ?? '') === 'ok';
}

/**
 * Section 9 : Branches conditionnelles (v19).
 */
function run_tests_advanced_conditional_workflow(): void {
    global $pdo;

    echo "── 9. Branches conditionnelles (v19) ──\n";

    // 9.1 — Migration v19 : colonne `condition` présente sur steps
    test('Colonne `condition` présente sur steps (migration v19)', function() use ($pdo) {
        $cols = $pdo->query("PRAGMA table_info(steps)")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        return in_array('condition', $names, true) ? true : 'Colonne `condition` absente. Cols: ' . implode(', ', $names);
    });

    test('schema_version marque v19', function() use ($pdo) {
        $v = (int) $pdo->query("SELECT COUNT(*) FROM schema_version WHERE version = 19")->fetchColumn();
        return $v > 0 ? true : 'v19 non marquée dans schema_version';
    });

    // 9.2 — evaluate_step_condition() : étape sans condition
    test('evaluate_step_condition() sans condition → true (rétrocompat)', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        // Soumission (sans rgpd_consent — non requis en DB test)
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // Récupère l'étape 1 (pas de condition)
        $stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? AND ordre = 1 LIMIT 1");
        $stmt->execute([$form_id]);
        $step1 = $stmt->fetch(PDO::FETCH_ASSOC);

        $result = evaluate_step_condition($step1, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result === true ? true : 'Étape sans condition doit toujours s\'exécuter';
    });

    test('evaluate_step_condition() avec condition JSON invalide → true (sécurité)', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        $step = ['id' => 'fake', 'condition' => '{invalid json'];
        $result = evaluate_step_condition($step, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result === true ? true : 'JSON invalide → doit exécuter par sécurité';
    });

    // 9.3 — equals (vraie / fausse)
    test('evaluate_step_condition() equals vraie → true', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // Sauvegarder decision_sg = "Acceptée"
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Acceptée', 'validator');

        // Récupère l'étape 2 (condition: decision_sg equals "Acceptée")
        $stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? AND ordre = 2 AND label LIKE '%Logistique%' LIMIT 1");
        $stmt->execute([$form_id]);
        $step2 = $stmt->fetch(PDO::FETCH_ASSOC);

        $result = evaluate_step_condition($step2, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result === true ? true : 'Condition equals "Acceptée" doit être vraie quand decision_sg="Acceptée"';
    });

    test('evaluate_step_condition() equals fausse → false', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // Sauvegarder decision_sg = "Refusée"
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Refusée', 'validator');

        $stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? AND ordre = 2 AND label LIKE '%Logistique%' LIMIT 1");
        $stmt->execute([$form_id]);
        $step2 = $stmt->fetch(PDO::FETCH_ASSOC);

        $result = evaluate_step_condition($step2, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result === false ? true : 'Condition equals "Acceptée" doit être fausse quand decision_sg="Refusée"';
    });

    test('evaluate_step_condition() equals fausse quand champ vide', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // Pas de save_validator_data → champ vide
        $stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? AND ordre = 2 AND label LIKE '%Logistique%' LIMIT 1");
        $stmt->execute([$form_id]);
        $step2 = $stmt->fetch(PDO::FETCH_ASSOC);

        $result = evaluate_step_condition($step2, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result === false ? true : 'Condition equals doit être fausse si champ vide';
    });

    // 9.4 — Autres opérateurs
    test('evaluate_step_condition() not_equals vraie', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Refusée', 'validator');

        $step = [
            'id' => 'fake',
            'condition' => json_encode(['field' => 'decision_sg', 'op' => 'not_equals', 'value' => 'Acceptée'], JSON_UNESCAPED_UNICODE),
        ];
        $result = evaluate_step_condition($step, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
        return $result === true ? true : 'not_equals "Acceptée" doit être vraie si decision_sg="Refusée"';
    });

    test('evaluate_step_condition() contains vraie', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Acceptée avec réserves', 'validator');

        $step = [
            'id' => 'fake',
            'condition' => json_encode(['field' => 'decision_sg', 'op' => 'contains', 'value' => 'Acceptée'], JSON_UNESCAPED_UNICODE),
        ];
        $result = evaluate_step_condition($step, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
        return $result === true ? true : 'contains "Acceptée" doit être vraie dans "Acceptée avec réserves"';
    });

    test('evaluate_step_condition() not_empty vraie', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Acceptée', 'validator');

        $step = [
            'id' => 'fake',
            'condition' => json_encode(['field' => 'decision_sg', 'op' => 'not_empty', 'value' => ''], JSON_UNESCAPED_UNICODE),
        ];
        $result = evaluate_step_condition($step, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
        return $result === true ? true : 'not_empty doit être vraie si champ rempli';
    });

    test('evaluate_step_condition() empty vraie', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);
        // Pas de save → champ vide

        $step = [
            'id' => 'fake',
            'condition' => json_encode(['field' => 'decision_sg', 'op' => 'empty', 'value' => ''], JSON_UNESCAPED_UNICODE),
        ];
        $result = evaluate_step_condition($step, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
        return $result === true ? true : 'empty doit être vraie si champ vide';
    });

    test('evaluate_step_condition() opérateur inconnu → true (sécurité)', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'test@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        $step = [
            'id' => 'fake',
            'condition' => json_encode(['field' => 'decision_sg', 'op' => 'unknown_op', 'value' => 'x'], JSON_UNESCAPED_UNICODE),
        ];
        $result = evaluate_step_condition($step, $sub_id);

        _cleanup_conditional_test_form($pdo, $form_id);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);
        return $result === true ? true : 'Opérateur inconnu → doit exécuter par sécurité';
    });

    // 9.5 — advance_workflow() : scénario "Refusée" → étapes 2 skippées → clôture directe
    test('advance_workflow() Refusée → étapes 2 skippées, soumission clôturée', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'agent@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // 1er appel : démarre l'étape 1 (owner)
        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Vérifier que seule l'étape 1 a des tokens
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT step_id) FROM tokens WHERE submission_id = ?");
        $stmt->execute([$sub_id]);
        $distinct = (int)$stmt->fetchColumn();
        if ($distinct !== 1) {
            _cleanup_conditional_test_form($pdo, $form_id);
            return "Attendu 1 step avec tokens, trouvé $distinct";
        }

        // Sauvegarder decision_sg = "Refusée" AVANT de valider l'étape 1
        // (simule le validateur qui remplit le champ puis valide)
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Refusée', 'validator');

        // Valider le token de l'étape 1 → déclenche advance_workflow()
        $validated = _validate_first_pending_token($pdo, $sub_id);
        if (!$validated) {
            _cleanup_conditional_test_form($pdo, $form_id);
            return 'Étape 1 n\'a pas pu être validée';
        }

        // Vérifier qu'aucun token n'a été généré pour les étapes 2 (ordr 2)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tokens t
            JOIN steps s ON s.id = t.step_id
            WHERE t.submission_id = ? AND s.ordre = 2
        ");
        $stmt->execute([$sub_id]);
        $tokens_step2 = (int)$stmt->fetchColumn();

        // Vérifier que la soumission est clôturée (status='valide')
        $stmt = $pdo->prepare("SELECT status, closed_at FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $status = $row['status'] ?? '';
        $closed = !empty($row['closed_at']);

        _cleanup_conditional_test_form($pdo, $form_id);

        if ($tokens_step2 > 0) return "Attendu 0 token sur étape 2, trouvé $tokens_step2";
        if ($status !== 'valide') return "Status attendu 'valide', trouvé '$status'";
        if (!$closed) return 'closed_at attendu non NULL';
        return true;
    });

    // 9.6 — advance_workflow() : scénario "Acceptée" → étapes 2 s'exécutent
    test('advance_workflow() Acceptée → étapes 2 (parallèles) s\'exécutent', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'agent@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // 1er appel : démarre l'étape 1
        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Sauvegarder decision_sg = "Acceptée"
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Acceptée', 'validator');

        // Valider le token de l'étape 1 → déclenche advance_workflow()
        $validated = _validate_first_pending_token($pdo, $sub_id);
        if (!$validated) {
            _cleanup_conditional_test_form($pdo, $form_id);
            return 'Étape 1 n\'a pas pu être validée';
        }

        // Vérifier que les étapes 2 (Logistique + DSI, parallèles) ont des tokens
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT t.step_id) FROM tokens t
            JOIN steps s ON s.id = t.step_id
            WHERE t.submission_id = ? AND s.ordre = 2
        ");
        $stmt->execute([$sub_id]);
        $distinct_step2 = (int)$stmt->fetchColumn();

        // La soumission ne doit PAS être clôturée (étapes 2 en cours)
        $stmt = $pdo->prepare("SELECT status, closed_at FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $status = $row['status'] ?? '';

        _cleanup_conditional_test_form($pdo, $form_id);

        if ($distinct_step2 !== 2) return "Attendu 2 steps parallèles à l'ordre 2, trouvé $distinct_step2";
        if ($status === 'valide') return 'Soumission ne doit pas être clôturée';
        return true;
    });

    // 9.7 — Toutes les étapes d'un ordre skippées → avance à l'ordre suivant
    test('advance_workflow() toutes étapes d\'un ordre skippées → avance', function() use ($pdo) {
        // Construire un formulaire avec :
        $form_id = generate_uuid();
        $slug = 'test_skip_ordre_' . substr(generate_uuid(), 0, 8);
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$form_id, $slug, 'Test Skip Ordre v19']);

        $field_id = generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, '', 0, 1, 'Décision', 'validator', '', 'all')")
            ->execute([$field_id, $form_id, 'Décision', 'select', 'decision_sg', json_encode(['Oui', 'Non'], JSON_UNESCAPED_UNICODE)]);

        $s1 = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 1, 1, '')")
            ->execute([$s1, $form_id, 'Étape 1']);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")->execute([generate_uuid(), $s1, 's1@e2e.test']);

        $s2 = generate_uuid();
        $cond = json_encode(['field' => 'decision_sg', 'op' => 'equals', 'value' => 'Oui'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 2, 1, ?)")
            ->execute([$s2, $form_id, 'Étape 2 conditionnelle', $cond]);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")->execute([generate_uuid(), $s2, 's2@e2e.test']);

        $s3 = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 3, 1, '')")
            ->execute([$s3, $form_id, 'Étape 3 finale']);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")->execute([generate_uuid(), $s3, 's3@e2e.test']);

        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'agent@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        // Démarrer étape 1
        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // decision_sg = "Non" → étape 2 skippée
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Non', 'validator');

        // Valider étape 1 → déclenche advance_workflow()
        _validate_first_pending_token($pdo, $sub_id);

        // Vérifier que l'étape 2 n'a pas de tokens (skippée)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $stmt->execute([$sub_id, $s2]);
        $tokens_s2 = (int)$stmt->fetchColumn();

        // Vérifier que l'étape 3 a des tokens (advance a sauté l'ordre 2)
        $stmt->execute([$sub_id, $s3]);
        $tokens_s3 = (int)$stmt->fetchColumn();

        // Statut ne doit pas être 'valide' (étape 3 en cours)
        $stmt = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $status = (string)$stmt->fetchColumn();

        _cleanup_conditional_test_form($pdo, $form_id);

        if ($tokens_s2 > 0) return "Étape 2 skippée ne doit pas avoir de tokens ($tokens_s2)";
        if ($tokens_s3 === 0) return 'Étape 3 aurait dû recevoir des tokens après skip de l\'ordre 2';
        if ($status === 'valide') return 'Soumission ne doit pas être clôturée (étape 3 en cours)';
        return true;
    });

    // 9.8 — Tous les ordres restants skippés → clôture
    test('advance_workflow() tous ordres suivants skippés → clôture', function() use ($pdo) {
        // Formulaire avec :
        $form_id = generate_uuid();
        $slug = 'test_skip_all_' . substr(generate_uuid(), 0, 8);
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$form_id, $slug, 'Test Skip All v19']);

        $field_id = generate_uuid();
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, '', 0, 1, 'Décision', 'validator', '', 'all')")
            ->execute([$field_id, $form_id, 'Décision', 'select', 'decision_sg', json_encode(['Oui', 'Non'], JSON_UNESCAPED_UNICODE)]);

        $s1 = generate_uuid();
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 1, 1, '')")
            ->execute([$s1, $form_id, 'Étape 1']);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")->execute([generate_uuid(), $s1, 's1@e2e.test']);

        $s2 = generate_uuid();
        $cond = json_encode(['field' => 'decision_sg', 'op' => 'equals', 'value' => 'Oui'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, 2, 1, ?)")
            ->execute([$s2, $form_id, 'Étape 2', $cond]);
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")->execute([generate_uuid(), $s2, 's2@e2e.test']);

        $sub_id = generate_uuid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', 'agent@e2e.test', 'en_cours', datetime('now'))")
            ->execute([$sub_id, $form_id]);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);
        \App\Core\App::validatorData()->saveValidatorData($sub_id, 'decision_sg', 'Non', 'validator');
        _validate_first_pending_token($pdo, $sub_id);

        // Vérifier qu'aucun token n'a été créé pour l'étape 2
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $stmt->execute([$sub_id, $s2]);
        $tokens_s2 = (int)$stmt->fetchColumn();

        // Vérifier que la soumission est clôturée (status='valide')
        $stmt = $pdo->prepare("SELECT status, closed_at FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $status = $row['status'] ?? '';

        _cleanup_conditional_test_form($pdo, $form_id);

        if ($tokens_s2 > 0) return "Étape 2 skippée ne doit pas avoir de tokens ($tokens_s2)";
        if ($status !== 'valide') return "Status attendu 'valide', trouvé '$status'";
        return true;
    });

    // 9.9 — validate_form_json() valide condition (objet valide)
    test('validate_form_json() accepte condition objet valide', function() {
        $data = [
            'schema_version' => '1.0',
            'form' => ['label' => 'Test', 'slug' => 'test'],
            'fields' => [],
            'steps' => [
                [
                    'label' => 'Étape 1',
                    'ordre' => 1,
                    'actif' => true,
                    'recipients' => ['val@e2e.test'],
                ],
                [
                    'label' => 'Étape 2',
                    'ordre' => 2,
                    'actif' => true,
                    'recipients' => ['val2@e2e.test'],
                    'condition' => ['field' => 'decision_sg', 'op' => 'equals', 'value' => 'Acceptée'],
                ],
            ],
        ];
        $result = validate_form_json($data);
        return $result['valid'] ? true : 'Erreurs: ' . implode(' | ', $result['errors']);
    });

    test('validate_form_json() rejette opérateur invalide', function() {
        $data = [
            'schema_version' => '1.0',
            'form' => ['label' => 'Test', 'slug' => 'test'],
            'fields' => [],
            'steps' => [
                [
                    'label' => 'Étape 2',
                    'ordre' => 2,
                    'actif' => true,
                    'recipients' => ['val@e2e.test'],
                    'condition' => ['field' => 'decision_sg', 'op' => 'invalid_op', 'value' => 'X'],
                ],
            ],
        ];
        $result = validate_form_json($data);
        $has_op_error = false;
        foreach ($result['errors'] as $e) {
            if (strpos($e, 'condition.op') !== false) $has_op_error = true;
        }
        return $has_op_error ? true : 'Opérateur invalide aurait dû être rejeté. Erreurs: ' . implode(' | ', $result['errors']);
    });

    test('validate_form_json() warning si condition sur étape d\'ordre 1', function() {
        $data = [
            'schema_version' => '1.0',
            'form' => ['label' => 'Test', 'slug' => 'test'],
            'fields' => [],
            'steps' => [
                [
                    'label' => 'Étape 1',
                    'ordre' => 1,
                    'actif' => true,
                    'recipients' => ['val@e2e.test'],
                    'condition' => ['field' => 'decision_sg', 'op' => 'equals', 'value' => 'Acceptée'],
                ],
            ],
        ];
        $result = validate_form_json($data);
        $has_warning = false;
        foreach ($result['warnings'] as $w) {
            if (strpos($w, "ordre 1") !== false) $has_warning = true;
        }
        return $has_warning ? true : 'Warning attendu pour condition sur ordre 1. Warnings: ' . implode(' | ', $result['warnings']);
    });

    // 9.10 — Test SQL : SELECT avec mot-clé `condition` (vérifie que PDO tolère)
    test('SELECT * FROM steps retourne la colonne `condition`', function() use ($pdo) {
        $form_id = _create_conditional_test_form($pdo);
        $stmt = $pdo->prepare("SELECT * FROM steps WHERE form_id = ? ORDER BY ordre LIMIT 1");
        $stmt->execute([$form_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        _cleanup_conditional_test_form($pdo, $form_id);
        return array_key_exists('condition', $row) ? true : 'Colonne `condition` absente du SELECT *';
    });

    echo "\n";
}
