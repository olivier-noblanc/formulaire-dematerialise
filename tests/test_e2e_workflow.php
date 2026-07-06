<?php
/**
 * tests/test_e2e_workflow.php — Sections 2 + 3 + 4 : Soumission + Workflow + Workflow complet
 *
 * Section 2 : Soumission complète d'un formulaire onboarding
 * Section 3 : Workflow — Avancement et validation étape par étape
 * Section 4 : Workflow complet — Soumission jusqu'à validation finale
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id, $steps_onboarding, $fields_onboarding,
 *                      $submission_uuid, $agent_email, $data_json, $full_workflow_uuid.
 */

declare(strict_types=1);

/**
 * Section 2 : Soumission complète d'un formulaire onboarding.
 */
function run_tests_e2e_submission(): void {
    global $pdo, $onboarding_id, $fields_onboarding, $submission_uuid, $agent_email, $data_json;

    echo "── 2. Soumission complète d'un formulaire onboarding ──\n";

    // Construire des données de formulaire réalistes
    $form_data = [
        'nom' => 'Martin',
        'prenom' => 'Sophie',
        'date_naissance' => '1988-03-22',
        'corps_grade' => 'Attachée d\'administration',
        'service_affectation' => 'Service Emploi',
        'date_prise_poste' => '2026-07-01',
        'type_arrivee' => 'Mutation',
        'quotite' => '100%',
    ];

    // Ajouter des données pour chaque champ existant
    foreach ($fields_onboarding as $field) {
        if (!isset($form_data[$field['field_name']])) {
            switch ($field['field_type']) {
                case 'date':
                    $form_data[$field['field_name']] = '2026-07-01';
                    break;
                case 'select':
                    // Prendre la première option disponible
                    $options = json_decode($field['options'] ?? '[]', true);
                    $form_data[$field['field_name']] = !empty($options) ? $options[0] : 'Option A';
                    break;
                case 'checkbox':
                    $form_data[$field['field_name']] = '1';
                    break;
                case 'textarea':
                    $form_data[$field['field_name']] = 'Commentaire de test E2E';
                    break;
                case 'file':
                    $form_data[$field['field_name']] = 'test_document.pdf';
                    break;
                default:
                    $form_data[$field['field_name']] = 'Valeur test E2E';
            }
        }
    }

    $data_json = json_encode($form_data, JSON_UNESCAPED_UNICODE);
    $submission_uuid = generate_uuid();
    $agent_email = 'sophie.martin@e2e.test';

    test('Insertion soumission onboarding', function() use ($pdo, $submission_uuid, $onboarding_id, $data_json, $agent_email) {
        $stmt = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, rgpd_consent) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'), 1)");
        $result = $stmt->execute([$submission_uuid, $onboarding_id, $data_json, $agent_email]);
        return $result ? true : 'Échec insertion soumission';
    });

    test('Soumission est récupérable par UUID', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT id, status, submitted_by FROM submissions WHERE id = ?");
        $stmt->execute([$submission_uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'Soumission non trouvée';
        if ($row['status'] !== 'en_cours') return 'Status incorrect: ' . $row['status'];
        if ($row['submitted_by'] !== 'sophie.martin@e2e.test') return 'Email agent incorrect';
        return true;
    });

    test('Données JSON sont conformes', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $stmt->execute([$submission_uuid]);
        $data = $stmt->fetchColumn();
        $decoded = json_decode($data, true);
        if (!$decoded) return 'JSON invalide';
        if (!isset($decoded['nom']) || $decoded['nom'] !== 'Martin') return 'Donnée nom manquante ou incorrecte';
        return true;
    });

    echo "\n";
}

/**
 * Section 3 : Workflow — Avancement et validation étape par étape.
 */
function run_tests_e2e_workflow_step(): void {
    global $pdo, $submission_uuid, $steps_onboarding;

    echo "── 3. Workflow complet — Avancement et validation ──\n";

    test('advance_workflow() crée les tokens de l\'étape 1', function() use ($submission_uuid, $pdo, $steps_onboarding) {
        advance_workflow($submission_uuid);

        $step1 = $steps_onboarding[0] ?? null;
        if (!$step1) return 'Pas d\'étape 1';

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $stmt->execute([$submission_uuid, $step1['id']]);
        $count = $stmt->fetchColumn();
        return $count > 0 ? true : "Aucun token pour l'étape 1 (step_id={$step1['id']})";
    });

    test('Les tokens générés sont des UUIDs valides', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT id FROM tokens WHERE submission_id = ?");
        $stmt->execute([$submission_uuid]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tokens as $tid) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tid)) {
                return "Token ID non-UUID: $tid";
            }
        }
        return true;
    });

    test('Les tokens de validation sont des chaînes de 64 hex', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ?");
        $stmt->execute([$submission_uuid]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tokens as $t) {
            if (!preg_match('/^[0-9a-f]{64}$/', $t)) {
                return "Token invalide (longueur=" . strlen($t) . "): " . substr($t, 0, 20) . "...";
            }
        }
        return true;
    });

    test('Les tokens ont une date d\'expiration', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT expires_at FROM tokens WHERE submission_id = ?");
        $stmt->execute([$submission_uuid]);
        $expires = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($expires as $exp) {
            if (empty($exp)) return 'Token sans date d\'expiration';
        }
        return true;
    });

    // Valider le premier token de l'étape 1
    $first_token = null;
    $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
    $stmt->execute([$submission_uuid]);
    $first_token = $stmt->fetchColumn();
    // Persister pour les sections suivantes (sécurité section 8 utilise $first_token)
    $GLOBALS['first_token'] = $first_token;

    test('validate_token() avec token valide retourne ok', function() use ($first_token) {
        if (!$first_token) return 'Pas de token à valider';
        $result = validate_token($first_token, 'valider', 'Validation E2E test');
        return $result['status'] === 'ok' ? true : 'Status: ' . $result['status'] . ' — ' . json_encode($result);
    });

    test('Token validé a done_at renseigné', function() use ($pdo, $first_token) {
        if (!$first_token) return 'Pas de token';
        $stmt = $pdo->prepare("SELECT done_at FROM tokens WHERE token = ?");
        $stmt->execute([$first_token]);
        $done_at = $stmt->fetchColumn();
        return !empty($done_at) ? true : 'done_at toujours NULL après validation';
    });

    test('Validation enregistrée dans data.validations', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $stmt->execute([$submission_uuid]);
        $data = json_decode($stmt->fetchColumn(), true);
        if (!isset($data['validations']) || !is_array($data['validations'])) {
            return 'Pas de validations[] dans data';
        }
        return count($data['validations']) > 0 ? true : 'validations[] vide';
    });

    // Si l'étape 1 a plusieurs destinataires, valider tous les tokens restants de l'étape 1
    $step1_remaining = $pdo->prepare("
        SELECT t.token FROM tokens t
        JOIN steps s ON t.step_id = s.id
        WHERE t.submission_id = ? AND s.ordre = 1 AND t.done_at IS NULL
    ");
    $step1_remaining->execute([$submission_uuid]);
    $remaining_tokens_step1 = $step1_remaining->fetchAll(PDO::FETCH_COLUMN);

    foreach ($remaining_tokens_step1 as $i => $token) {
        test("Validation token étape 1 #" . ($i + 2), function() use ($token) {
            $result = validate_token($token, 'valider', 'Validation parallèle E2E');
            return $result['status'] === 'ok' ? true : 'Status: ' . $result['status'];
        });
    }

    test('Après validation étape 1, étape 2 a des tokens', function() use ($pdo, $submission_uuid, $steps_onboarding) {
        if (count($steps_onboarding) < 2) return 'Pas d\'étape 2';
        $step2 = $steps_onboarding[1];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND step_id = ?");
        $stmt->execute([$submission_uuid, $step2['id']]);
        $count = $stmt->fetchColumn();
        return $count > 0 ? true : "Aucun token pour l'étape 2";
    });

    // Valider l'étape 2
    $step2_token = null;
    if (count($steps_onboarding) >= 2) {
        $step2 = $steps_onboarding[1];
        $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND step_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$submission_uuid, $step2['id']]);
        $step2_token = $stmt->fetchColumn();
    }

    test('Validation étape 2', function() use ($step2_token) {
        if (!$step2_token) return 'Pas de token étape 2 (ignoré si < 2 étapes)';
        $result = validate_token($step2_token, 'valider', 'Validation étape 2 E2E');
        return $result['status'] === 'ok' ? true : 'Status: ' . $result['status'];
    });

    echo "\n";
}

/**
 * Section 4 : Workflow complet — Soumission jusqu'à validation finale.
 */
function run_tests_e2e_workflow_full(): void {
    global $pdo, $onboarding_id;

    echo "── 4. Workflow complet — De la soumission à la clôture ──\n";

    $full_workflow_uuid = generate_uuid();
    $GLOBALS['full_workflow_uuid'] = $full_workflow_uuid;
    $full_data = json_encode([
        'nom' => 'Leroy',
        'prenom' => 'Pierre',
        'date_prise_poste' => '2026-08-01',
        'type_arrivee' => 'Nouvelle affectation',
    ], JSON_UNESCAPED_UNICODE);

    test('Création soumission pour workflow complet', function() use ($pdo, $full_workflow_uuid, $onboarding_id, $full_data) {
        $stmt = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, rgpd_consent) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'), 1)");
        return $stmt->execute([$full_workflow_uuid, $onboarding_id, $full_data, 'pierre.leroy@e2e.test']) ? true : 'Échec';
    });

    test('Advance workflow pour soumission complète', function() use ($full_workflow_uuid) {
        advance_workflow($full_workflow_uuid);
        return true;
    });

    // Valider TOUTES les étapes une par une
    $all_steps = $pdo->prepare("SELECT id, label, ordre FROM steps WHERE form_id = ? ORDER BY ordre");
    $all_steps->execute([$onboarding_id]);
    $all_steps_rows = $all_steps->fetchAll(PDO::FETCH_ASSOC);

    $workflow_completed = false;
    $final_status = null;

    foreach ($all_steps_rows as $idx => $step) {
        // Récupérer les tokens pour cette étape
        $tok_stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND step_id = ? AND done_at IS NULL");
        $tok_stmt->execute([$full_workflow_uuid, $step['id']]);
        $step_tokens = $tok_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($step_tokens as $tidx => $token) {
            $result = validate_token($token, 'valider', "Validation complète étape " . ($idx + 1));
            if ($result['status'] !== 'ok') {
                test("Validation étape {$step['label']} token #" . ($tidx + 1), function() use ($result) {
                    return 'Échec: ' . $result['status'];
                });
            }
        }

        // Vérifier le statut après chaque étape
        $status_stmt = $pdo->prepare("SELECT status, closed_at FROM submissions WHERE id = ?");
        $status_stmt->execute([$full_workflow_uuid]);
        $row = $status_stmt->fetch(PDO::FETCH_ASSOC);
        $final_status = $row['status'];

        if ($final_status === 'valide') {
            $workflow_completed = true;
            break;
        }
    }

    test('Workflow se termine avec status "valide"', function() use ($final_status) {
        return $final_status === 'valide' ? true : "Status final: $final_status (attendu: valide)";
    });

    test('closed_at est renseigné quand le workflow est terminé', function() use ($pdo, $full_workflow_uuid) {
        $stmt = $pdo->prepare("SELECT closed_at FROM submissions WHERE id = ?");
        $stmt->execute([$full_workflow_uuid]);
        $closed_at = $stmt->fetchColumn();
        return !empty($closed_at) ? true : 'closed_at toujours NULL';
    });

    test('Historique des validations complet dans data', function() use ($pdo, $full_workflow_uuid, $all_steps_rows) {
        $stmt = $pdo->prepare("SELECT data FROM submissions WHERE id = ?");
        $stmt->execute([$full_workflow_uuid]);
        $data = json_decode($stmt->fetchColumn(), true);
        $validations = $data['validations'] ?? [];
        // Devrait avoir au moins autant de validations que d'étapes
        return count($validations) >= count($all_steps_rows) ? true :
            'Validations: ' . count($validations) . ' < étapes: ' . count($all_steps_rows);
    });

    echo "\n";
}
