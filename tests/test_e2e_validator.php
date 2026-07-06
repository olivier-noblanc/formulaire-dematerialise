<?php
/**
 * tests/test_e2e_validator.php — Sections 16 + 17 : Champs validateur + E2E POST validate.php
 *
 * Section 16 : Champs validateur (filled_by = 'validator') — colonnes, samples,
 *              get_form_fields(filled_by), save_validator_data, export JSON, validate.php render.
 * Section 17 : Cycle complet validate.php avec champ validator (filled_by) — simule
 *              le POST handler de validate.php (pre-check required → validate_token() →
 *              save_validator_data() avec audit).
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id.
 *
 * État partagé entre les sous-tests 17.x via $GLOBALS['test_vd_*'] :
 *   - test_vd_form_id       : ID du formulaire de test (déterministe)
 *   - test_vd_step_id       : UUID du step "Validation manager"
 *   - test_vd_submission_id : UUID de la soumission
 *   - test_vd_token_id      : UUID du token (row DB)
 *   - test_vd_token_value   : valeur 64-hex du token (URL)
 */

declare(strict_types=1);

/**
 * Section 16 : Champs validateur (filled_by = Option A).
 */
function run_tests_e2e_validator_fields(): void {
    global $pdo, $onboarding_id;

    echo "── 16. Champs validateur (filled_by) ──\n";

    // 16.1 : Colonnes filled_by et validator_step existent
    test('Table form_fields a la colonne filled_by', function() use ($pdo) {
        $info = $pdo->query("PRAGMA table_info(form_fields)")->fetchAll(PDO::FETCH_ASSOC);
        $has_col = false;
        foreach ($info as $c) { if ($c['name'] === 'filled_by') $has_col = true; }
        return $has_col ? true : 'Colonne filled_by manquante';
    });

    test('Table form_fields a la colonne validator_step', function() use ($pdo) {
        $info = $pdo->query("PRAGMA table_info(form_fields)")->fetchAll(PDO::FETCH_ASSOC);
        $has_col = false;
        foreach ($info as $c) { if ($c['name'] === 'validator_step') $has_col = true; }
        return $has_col ? true : 'Colonne validator_step manquante';
    });

    test('Table submission_validator_data existe', function() use ($pdo) {
        $result = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='submission_validator_data'")->fetchColumn();
        return $result === 'submission_validator_data' ? true : 'Table submission_validator_data manquante';
    });

    // 16.2 : Les samples ont des champs validator
    test('Un champ validator existe dans onboarding', function() use ($pdo, $onboarding_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM form_fields WHERE form_id = ? AND filled_by = 'validator'");
        $stmt->execute([$onboarding_id]);
        $count = (int)$stmt->fetchColumn();
        return $count > 0 ? true : "Aucun champ validator dans onboarding (id=$onboarding_id)";
    });

    test('Les champs demandeur excluent les validator', function() use ($pdo, $onboarding_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM form_fields WHERE form_id = ? AND (filled_by IS NULL OR filled_by = 'demandeur')");
        $stmt->execute([$onboarding_id]);
        $count = (int)$stmt->fetchColumn();
        return $count > 0 ? true : "Aucun champ demandeur";
    });

    test('get_form_fields() filtre par filled_by', function() use ($pdo, $onboarding_id) {
        // Test via la fonction PHP
        $all = get_form_fields($onboarding_id);
        $validator = get_form_fields($onboarding_id, 'validator');
        $demandeur = get_form_fields($onboarding_id, 'demandeur');
        if (empty($all)) return 'Aucun champ du tout';
        if (empty($validator)) return 'get_form_fields(..., "validator") retourne rien';
        if (empty($demandeur)) return 'get_form_fields(..., "demandeur") retourne rien';
        // L'intersection doit être vide
        $all_fields = array_column($all, 'field_name');
        $validator_fields = array_column($validator, 'field_name');
        $demandeur_fields = array_column($demandeur, 'field_name');
        // Vérifier qu'aucun champ n'est dans les 2 listes
        $overlap = array_intersect($validator_fields, $demandeur_fields);
        if (!empty($overlap)) return "Champs dans les 2 catégories: " . implode(', ', $overlap);
        return true;
    });

    // 16.3 : save_validator_data / get_submission_validator_data
    test('save_validator_data et get_submission_validator_data fonctionnent', function() use ($pdo, $onboarding_id) {
        // Créer une soumission de test
        $sub_id = generate_uuid();
        $stmt = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at) VALUES (?, ?, ?, ?, datetime('now'))");
        $stmt->execute([$sub_id, $onboarding_id, json_encode(['nom_complet' => 'Test User']), 'test@e2e.test']);

        // Sauvegarder une donnée validator
        save_validator_data($sub_id, 'decision_validation', 'Accepté', 'validator', null);

        // Récupérer
        $data = get_submission_validator_data($sub_id);
        if (empty($data)) { return 'get_submission_validator_data retourne rien'; }
        $found = false;
        foreach ($data as $row) {
            if ($row['field_name'] === 'decision_validation' && $row['value'] === 'Accepté') {
                $found = true; break;
            }
        }
        if (!$found) return 'Valeur "Accepté" non trouvée dans les données validator';

        // Upsert : modifier la valeur
        save_validator_data($sub_id, 'decision_validation', 'Accepté avec réserves', 'validator', null);
        $data2 = get_submission_validator_data($sub_id);
        $count = 0;
        foreach ($data2 as $row) { if ($row['field_name'] === 'decision_validation') $count++; }
        if ($count !== 1) return "Expected 1 row for decision_validation, got $count";

        // Nettoyer
        $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM attachments WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return true;
    });

    // 16.4 : Export JSON inclut filled_by + validator_step
    test('Export JSON inclut filled_by et validator_step', function() use ($pdo, $onboarding_id) {
        // Requête directe simulant l'export
        $stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
        $stmt->execute([$onboarding_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return 'Aucun champ trouvé';
        $first = $rows[0];
        if (!isset($first['filled_by'])) return 'filled_by absent de la sélection';
        if (!isset($first['validator_step'])) return 'validator_step absent de la sélection';
        return true;
    });

    // 16.5 : validate.php - rendu des champs validator
    test('validate.php rend les champs validator', function() use ($onboarding_id) {
        // On ne peut pas tester le rendu HTML directement ici,
        // mais on vérifie que get_form_validator_fields fonctionne
        $fields = get_form_validator_fields($onboarding_id);
        if (empty($fields)) return 'get_form_validator_fields retourne rien';
        $has_decision = false;
        foreach ($fields as $f) {
            if ($f['field_name'] === 'decision_validation') {
                $has_decision = true; break;
            }
        }
        return $has_decision ? true : 'decision_validation non trouvé dans les champs validator';
    });

    echo "\n";
}

/**
 * Section 17 : E2E POST validate.php avec champ validator (filled_by).
 *
 * P3-A / issue #16 : la section 16 ci-dessus appelle save_validator_data()
 * directement en PHP, ce qui ne teste PAS le flux réel de validate.php
 * (POST HTTP → pre-check required → validate_token() → advance_workflow()
 * → save_validator_data() avec audit). Cette section 17 simule ce cycle
 * complet en répliquant la logique du handler POST de validate.php
 * (cf. validate.php lignes 33-155) sans inclure le fichier (qui ferait
 * `test_json_response()` + `exit`). On appelle directement les helpers
 * dans le même ordre, avec les mêmes arguments, et on vérifie à chaque
 * étape que l'état de la DB est cohérent.
 */
function run_tests_e2e_validator_cycle(): void {
    global $pdo;

    echo "── 17. Cycle complet validate.php avec champ validator ──\n";

    // 17.0 : Nettoyage préalable (idempotence — permet de relancer le test
    // plusieurs fois sans collision sur les PK / UNIQUE).
    test('Nettoyage préalable des données test_vd_*', function() use ($pdo) {
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = 'test_vd_form_e2e')");
        $pdo->exec("DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = 'test_vd_form_e2e')");
        $pdo->exec("DELETE FROM submissions WHERE form_id = 'test_vd_form_e2e'");
        $pdo->exec("DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = 'test_vd_form_e2e')");
        $pdo->exec("DELETE FROM form_fields WHERE form_id = 'test_vd_form_e2e'");
        $pdo->exec("DELETE FROM steps WHERE form_id = 'test_vd_form_e2e'");
        $pdo->exec("DELETE FROM forms WHERE id = 'test_vd_form_e2e'");
        return true;
    });

    // 17.1 : Créer un formulaire avec 1 champ demandeur + 1 champ validator
    test('Création formulaire + champ validator', function() use ($pdo) {
        $form_id = 'test_vd_form_e2e';
        $step_id = generate_uuid();

        // Persister l'état partagé pour les sous-tests suivants
        $GLOBALS['test_vd_form_id'] = $form_id;
        $GLOBALS['test_vd_step_id'] = $step_id;

        // Formulaire (id deterministe pour faciliter le nettoyage)
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, '', 1, datetime('now'))")
            ->execute([$form_id, 'test_vd_e2e', 'Test Validator Data E2E']);

        // Étape "Validation manager" — c'est sur cette étape que le champ validator
        // sera visible (form_fields.validator_step = $step_id).
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)")
            ->execute([$step_id, $form_id, 'Validation manager']);

        // Destinataire du step (advance_workflow() lira step_recipients.email pour
        // générer les tokens — mais ici on crée le token nous-mêmes, ce n'est donc
        // pas strictement nécessaire. On l'ajoute quand même pour la cohérence
        // métier : un step sans destinataire est une anomalie.)
        $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)")
            ->execute([generate_uuid(), $step_id, 'validator@e2e.test']);

        // Champ demandeur (filled_by='demandeur') — nécessaire pour que
        // get_form_fields($form_id) ne soit pas vide (utile pour d'autres tests).
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, filled_by, validator_step) VALUES (?, ?, 'Nom', 'text', 'nom_vd', '', 1, 1, 'Identité', 'demandeur', '')")
            ->execute([generate_uuid(), $form_id]);

        // Champ validator (filled_by='validator', validator_step=$step_id).
        // required=1 pour tester le pre-check de validate.php (sous-test 17.5).
        $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, filled_by, validator_step) VALUES (?, ?, 'Décision', 'select', 'decision_vd', '', 1, 2, 'Décision', 'validator', ?)")
            ->execute([generate_uuid(), $form_id, $step_id]);

        // Vérifier que get_form_validator_fields() retrouve bien le champ
        $validator_fields = get_form_validator_fields($form_id, $step_id);
        if (empty($validator_fields)) return 'get_form_validator_fields ne retourne rien';
        $has_decision = false;
        foreach ($validator_fields as $vf) {
            if (($vf['field_name'] ?? '') === 'decision_vd') { $has_decision = true; break; }
        }
        return $has_decision ? true : 'decision_vd non trouvé dans les champs validator';
    });

    // 17.2 : Créer une soumission + un token pour cette étape
    test('Soumission + token créés', function() use ($pdo) {
        $form_id = $GLOBALS['test_vd_form_id'] ?? '';
        $step_id = $GLOBALS['test_vd_step_id'] ?? '';
        if ($form_id === '' || $step_id === '') return 'Variables de contexte manquantes (17.1 a échoué ?)';

        $sub_id = generate_uuid();
        $token_id = generate_uuid();
        $token_value = generate_token(); // 64 hex chars (cf. lib_uuid.php)

        // Persister pour 17.3
        $GLOBALS['test_vd_submission_id'] = $sub_id;
        $GLOBALS['test_vd_token_id'] = $token_id;
        $GLOBALS['test_vd_token_value'] = $token_value;

        // Soumission "en_cours" (status par défaut). submitted_by doit être un
        // email valide car validate_token() peut notifier l'agent.
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$sub_id, $form_id, json_encode(['nom_vd' => 'Test E2E']), 'agent@e2e.test']);

        // Token non encore validé (done_at IS NULL). email = validateur cible.
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
            ->execute([$token_id, $sub_id, $step_id, 'validator@e2e.test', $token_value]);

        // Vérifier que get_token_with_context() retrouve le token
        $ctx = get_token_with_context($token_value);
        if (!$ctx) return 'get_token_with_context retourne null';
        if (($ctx['id'] ?? '') !== $token_id) return 'token_id mismatch';
        if (($ctx['submission_id'] ?? '') !== $sub_id) return 'submission_id mismatch';
        if (($ctx['step_id'] ?? '') !== $step_id) return 'step_id mismatch';
        if (($ctx['form_id'] ?? '') !== $form_id) return 'form_id mismatch';
        if (($ctx['email'] ?? '') !== 'validator@e2e.test') return 'email mismatch';
        return true;
    });

    // 17.3 : Simuler le POST handler de validate.php (action=valider) avec le
    // champ validator rempli, et vérifier que la donnée est persistée avec
    // toutes les colonnes d'audit (step_id, step_label, filled_by_email, token_id).
    test('validate_token() + save_validator_data() persiste le champ validator avec audit', function() use ($pdo) {
        $token_value = $GLOBALS['test_vd_token_value'] ?? '';
        if ($token_value === '') return 'Token non créé (17.2 a échoué ?)';

        // ── Étape 1 : pre-check required (cf. validate.php lignes 54-82) ──
        // Charger le contexte du token (lecture pure, pas d'effet de bord).
        $token_ctx = get_token_with_context($token_value);
        if (!$token_ctx) return 'get_token_with_context retourne null';
        if (!empty($token_ctx['done_at'])) return 'Token déjà validé (done_at non null)';

        // Charger les champs validator pour cette étape.
        $form_id = (string)($token_ctx['form_id'] ?? '');
        $step_id = isset($token_ctx['step_id']) ? (string)$token_ctx['step_id'] : null;
        $validator_fields = get_form_validator_fields($form_id, $step_id);
        if (empty($validator_fields)) return 'Aucun champ validator pour cette étape';

        // Simuler $_POST rempli (le validateur a saisi "Accepté" pour decision_vd)
        $_POST = ['decision_vd' => 'Accepté'];

        // Vérifier les champs required (logique identique à validate.php lignes 54-67)
        $missing = [];
        foreach ($validator_fields as $vf) {
            if (!empty($vf['required'])) {
                $fname = (string)($vf['field_name'] ?? '');
                if ($fname === '') continue;
                $val = trim((string)($_POST[$fname] ?? ''));
                if ($val === '') $missing[] = (string)($vf['label'] ?? $fname);
            }
        }
        if (!empty($missing)) return 'Pre-check required a détecté des manquants alors que tout est rempli: ' . implode(', ', $missing);

        // ── Étape 2 : validate_token() (valide le token + avance le workflow) ──
        // Le formulaire n'a qu'une seule étape → advance_workflow() va clore la
        // soumission (status='valide', closed_at=now). C'est attendu.
        $result = validate_token($token_value, 'valider', '');
        if (($result['status'] ?? '') !== 'ok') {
            return 'validate_token a échoué: ' . json_encode($result);
        }

        // ── Étape 3 : persister les champs validator (cf. validate.php lignes 122-155) ──
        $token_ctx_after = $result['data'] ?? [];
        $subm_id = (string)($token_ctx_after['submission_id'] ?? '');
        if ($subm_id === '') return '$result[\'data\'][\'submission_id\'] vide';
        $step_id_after = isset($token_ctx_after['step_id']) ? (string)$token_ctx_after['step_id'] : null;
        $validator_email = isset($token_ctx_after['email']) ? (string)$token_ctx_after['email'] : null;
        $token_id_after = isset($token_ctx_after['id']) ? (string)$token_ctx_after['id'] : null;

        foreach ($validator_fields as $vf) {
            $fname = (string)($vf['field_name'] ?? '');
            if ($fname === '') continue;
            $val = trim((string)($_POST[$fname] ?? ''));
            if ($val !== '') {
                // save_validator_data() fait un UPSERT (cf. helpers.php:2076).
                // step_label=null → résolu automatiquement par la fonction via $step_id.
                save_validator_data(
                    $subm_id,
                    $fname,
                    $val,
                    'validator',
                    $step_id_after,
                    null,             // step_label résolu auto
                    $validator_email, // audit : email du validateur
                    $token_id_after   // audit : ID du token utilisé
                );
            }
        }

        // ── Étape 4 : vérifier que la ligne est bien en DB avec toutes les colonnes d'audit ──
        $stmt = $pdo->prepare("SELECT * FROM submission_validator_data WHERE submission_id = ? AND field_name = 'decision_vd'");
        $stmt->execute([$subm_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'Donnée validator non persistée';
        if (($row['value'] ?? '') !== 'Accepté') return 'Valeur incorrecte: ' . ($row['value'] ?? '');
        if (($row['filled_by'] ?? '') !== 'validator') return 'filled_by incorrect: ' . ($row['filled_by'] ?? '');
        if (($row['step_id'] ?? '') !== $step_id_after) return 'step_id manquant ou incorrect (attendu ' . $step_id_after . ', obtenu ' . ($row['step_id'] ?? '') . ')';
        if (($row['step_label'] ?? '') !== 'Validation manager') return 'step_label manquant ou incorrect: ' . ($row['step_label'] ?? '');
        if (($row['filled_by_email'] ?? '') !== 'validator@e2e.test') return 'filled_by_email manquant ou incorrect: ' . ($row['filled_by_email'] ?? '');
        if (($row['token_id'] ?? '') !== $token_id_after) return 'token_id manquant ou incorrect (attendu ' . $token_id_after . ', obtenu ' . ($row['token_id'] ?? '') . ')';

        // ── Étape 5 : vérifier que advance_workflow() a bien avancé/clos la soumission ──
        // (1 seul step → soumission.close_at non null, status='valide')
        $stmt_sub = $pdo->prepare("SELECT closed_at, status FROM submissions WHERE id = ?");
        $stmt_sub->execute([$subm_id]);
        $sub_row = $stmt_sub->fetch(PDO::FETCH_ASSOC);
        if (empty($sub_row['closed_at'])) return 'Soumission non close (advance_workflow na pas avancé)';
        if (($sub_row['status'] ?? '') !== 'valide') return 'Statut soumission incorrect: ' . ($sub_row['status'] ?? '');

        // ── Étape 6 : vérifier que le token est marqué done_at ──
        $stmt_tok = $pdo->prepare("SELECT done_at FROM tokens WHERE id = ?");
        $stmt_tok->execute([$token_id_after]);
        $tok_done = (string)$stmt_tok->fetchColumn();
        if ($tok_done === '') return 'Token non marqué done_at';

        // Persister submission_id pour 17.4
        $GLOBALS['test_vd_submission_id'] = $subm_id;
        $GLOBALS['test_vd_step_id'] = $step_id_after;
        return true;
    });

    // 17.4 : get_submission_validator_data() retrouve la donnée avec step_id
    test('get_submission_validator_data() retrouve avec step_id', function() use ($pdo) {
        $sub_id = $GLOBALS['test_vd_submission_id'] ?? '';
        $step_id = $GLOBALS['test_vd_step_id'] ?? '';
        if ($sub_id === '' || $step_id === '') return 'Contexte manquant (17.3 a échoué ?)';

        // Sans filtre step_id → doit retourner la donnée
        $all = get_submission_validator_data($sub_id);
        if (empty($all)) return 'get_submission_validator_data() retourne vide sans filtre step_id';
        $found_all = false;
        foreach ($all as $row) {
            if (($row['field_name'] ?? '') === 'decision_vd' && ($row['value'] ?? '') === 'Accepté') {
                $found_all = true;
                // Vérifier que les colonnes d'audit sont bien remplies
                if (($row['step_id'] ?? '') !== $step_id) return 'step_id manquant dans la lecture (attendu ' . $step_id . ')';
                if (($row['filled_by_email'] ?? '') !== 'validator@e2e.test') return 'filled_by_email manquant dans la lecture';
                if (($row['step_label'] ?? '') !== 'Validation manager') return 'step_label manquant dans la lecture';
                break;
            }
        }
        if (!$found_all) return 'donnée decision_vd non trouvée sans filtre';

        // Avec filtre step_id → doit retourner la même donnée
        $filtered = get_submission_validator_data($sub_id, $step_id);
        if (empty($filtered)) return 'get_submission_validator_data($step_id) retourne vide';
        $found_filtered = false;
        foreach ($filtered as $row) {
            if (($row['field_name'] ?? '') === 'decision_vd') {
                $found_filtered = true;
                break;
            }
        }
        return $found_filtered ? true : 'donnée non trouvée avec filtre step_id';
    });

    // 17.5 : Pre-check required manquant → doit bloquer validate_token()
    // On simule la logique du pre-check de validate.php (lignes 54-82) en
    // isolated (pas d'appel à validate_token — le pre-check est précisément
    // là pour empêcher l'appel à validate_token en cas de champ required vide).
    test('Champ validator required manquant bloque le pre-check', function() use ($pdo) {
        // Reproduit la logique du pre-check de validate.php (lignes 54-82)
        $validator_fields = [
            ['field_name' => 'decision_vd', 'label' => 'Décision', 'required' => 1],
        ];
        // Simule $_POST avec decision_vd vide → le pre-check doit détecter le manquant
        $_POST = ['decision_vd' => ''];
        $missing = [];
        foreach ($validator_fields as $vf) {
            if (!empty($vf['required'])) {
                $fname = (string)($vf['field_name'] ?? '');
                if ($fname === '') continue;
                $val = trim((string)($_POST[$fname] ?? ''));
                if ($val === '') $missing[] = (string)($vf['label'] ?? $fname);
            }
        }
        if (count($missing) === 0) {
            return 'Le pre-check aurait dû détecter decision_vd comme manquant';
        }
        if (!in_array('Décision', $missing, true)) {
            return 'Le label manquant est incorrect: ' . implode(', ', $missing);
        }
        return true;
    });

    // 17.6 : delete_validator_data() efface la donnée
    test('delete_validator_data efface', function() use ($pdo) {
        $sub_id = $GLOBALS['test_vd_submission_id'] ?? '';
        if ($sub_id === '') return 'Contexte manquant (17.3 a échoué ?)';

        // Vérifier qu'on a bien une ligne avant
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_validator_data WHERE submission_id = ? AND field_name = 'decision_vd'");
        $stmt->execute([$sub_id]);
        $count_before = (int)$stmt->fetchColumn();
        if ($count_before !== 1) return 'Pré-condition: attendu 1 ligne, trouvé ' . $count_before;

        // delete_validator_data() — cf. helpers.php:2155
        delete_validator_data($sub_id, 'decision_vd');

        // Vérifier qu'il n'y a plus de ligne
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_validator_data WHERE submission_id = ? AND field_name = 'decision_vd'");
        $stmt->execute([$sub_id]);
        $count_after = (int)$stmt->fetchColumn();
        return $count_after === 0 ? true : 'La ligne est toujours présente (' . $count_after . ')';
    });

    // 17.7 : Nettoyage final des données de test de la section 17
    test('Nettoyage final des données test_vd_*', function() use ($pdo) {
        $form_id = $GLOBALS['test_vd_form_id'] ?? 'test_vd_form_e2e';
        $pdo->exec("DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = '" . $form_id . "')");
        $pdo->exec("DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = '" . $form_id . "')");
        $pdo->exec("DELETE FROM submissions WHERE form_id = '" . $form_id . "'");
        $pdo->exec("DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = '" . $form_id . "')");
        $pdo->exec("DELETE FROM form_fields WHERE form_id = '" . $form_id . "'");
        $pdo->exec("DELETE FROM steps WHERE form_id = '" . $form_id . "'");
        $pdo->exec("DELETE FROM forms WHERE id = '" . $form_id . "'");

        // Nettoyer aussi les variables globales
        unset($GLOBALS['test_vd_form_id'], $GLOBALS['test_vd_step_id'], $GLOBALS['test_vd_submission_id'], $GLOBALS['test_vd_token_id'], $GLOBALS['test_vd_token_value']);

        // Vérifier que tout est bien supprimé
        $count = (int)$pdo->query("SELECT COUNT(*) FROM forms WHERE id = '" . $form_id . "'")->fetchColumn();
        return $count === 0 ? true : 'Form ' . $form_id . ' toujours présent';
    });

    echo "\n";
}
