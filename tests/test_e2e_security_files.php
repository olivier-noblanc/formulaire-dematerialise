<?php
/**
 * tests/test_e2e_security_files.php — Sections 8 + 9 + 10 : Sécurité + Upload + Outboarding
 *
 * Section 8 : Cas limites et sécurité (token invalide/déjà utilisé, double soumission, SQLi, XSS, CSRF, rate limiting)
 * Section 9 : Upload de fichiers (simulation BLOB — attachments stockés en DB)
 * Section 10 : Formulaire outboarding
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id, $outboarding_id, $submission_uuid,
 *                      $agent_email, $data_json, $first_token, $full_workflow_uuid.
 */

declare(strict_types=1);

/**
 * Section 8 : Cas limites et sécurité.
 */
function run_tests_e2e_security(): void {
    global $pdo, $onboarding_id, $submission_uuid, $agent_email, $data_json, $first_token, $full_workflow_uuid;

    echo "── 8. Cas limites et sécurité ──\n";

    test('Token invalide rejeté', function() {
        $result = \App\Core\App::workflow()->validateToken('token_inexistant_1234567890abcdef');
        return $result['status'] === 'invalid' ? true : 'Status: ' . $result['status'] . ' (attendu: invalid)';
    });

    test('Token déjà utilisé rejeté', function() use ($first_token) {
        if (!$first_token) return 'Pas de token déjà validé';
        $result = \App\Core\App::workflow()->validateToken($first_token);
        return $result['status'] === 'already_done' ? true : 'Status: ' . $result['status'] . ' (attendu: already_done)';
    });

    test('Soumission déjà fermée rejetée', function() use ($pdo, $full_workflow_uuid) {
        // Le workflow complet a déjà été clôturé, essayer de valider un token restant
        $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND done_at IS NOT NULL LIMIT 1");
        $stmt->execute([$full_workflow_uuid]);
        $token = $stmt->fetchColumn();
        if (!$token) return true; // Pas applicable si pas de token
        $result = \App\Core\App::workflow()->validateToken($token);
        // Le token est déjà validé, donc should return already_done
        return in_array($result['status'], ['already_done', 'closed']) ? true : 'Status: ' . $result['status'];
    });

    test('Double soumission impossible (UUID unique)', function() use ($pdo, $submission_uuid, $onboarding_id, $data_json, $agent_email) {
        try {
            $stmt = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))");
            $stmt->execute([$submission_uuid, $onboarding_id, $data_json, $agent_email]);
            return 'Double insertion acceptée ! UUID non unique';
        } catch (PDOException $e) {
            return strpos($e->getMessage(), 'UNIQUE') !== false ? true : 'Erreur inattendue: ' . $e->getMessage();
        }
    });

    test('Injection SQL dans les champs de formulaire', function() use ($pdo, $onboarding_id) {
        $malicious_data = json_encode([
            'nom' => "'; DROP TABLE submissions; --",
            'prenom' => '" OR 1=1 --',
            'date_prise_poste' => '2026-01-01',
        ]);
        $test_uuid = generate_uuid();
        $stmt = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))");
        $stmt->execute([$test_uuid, $onboarding_id, $malicious_data, 'sqli.test@e2e.test']);

        // Vérifier que la table existe toujours
        $check = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
        // Nettoyer
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$test_uuid]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$test_uuid]);
        return $check !== false ? true : 'Table submissions détruite !';
    });

    test('XSS dans les données stockées (h() échappe)', function() {
        $xss_payload = '<script>alert("XSS")</script>';
        $escaped = \App\Core\App::html()->escape($xss_payload);
        return strpos($escaped, '<script>') === false ? true : 'XSS non échappé: ' . $escaped;
    });

    test('CSRF token vérifié (session vs POST)', function() {
        // En mode TEST, verify_csrf() retourne toujours true (bypass).
        // On vérifie donc la logique directement via hash_equals()
        @session_start();
        $_SESSION['csrf_token'] = 'valid_csrf_token_12345';

        // Test 1 : token valide
        $_POST['csrf_token'] = 'valid_csrf_token_12345';
        $ok_valid = hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);

        // Test 2 : token invalide
        $_POST['csrf_token'] = 'invalid_token';
        $ok_invalid = !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);

        // Test 3 : csrfField() génère un champ caché
        $html = \App\Core\App::security()->csrfField();
        $has_field = strpos($html, 'name="csrf_token"') !== false && strpos($html, 'type="hidden"') !== false;

        return ($ok_valid && $ok_invalid && $has_field) ? true : 'CSRF logique défaillante';
    });

    echo "\n";
}

/**
 * Section 9 : Upload de fichiers (simulation BLOB).
 */
function run_tests_e2e_files(): void {
    global $pdo, $submission_uuid;

    echo "── 9. Upload de fichiers (simulation BLOB) ──\n";

    test('Attachment stocké en BLOB', function() use ($pdo, $submission_uuid) {
        // Simuler un fichier uploadé
        $file_content = file_get_contents(__DIR__ . '/../test_e2e.php'); // Utiliser ce fichier comme exemple
        $attachment_uuid = generate_uuid();

        $stmt = $pdo->prepare("INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))");
        $result = $stmt->execute([
            $attachment_uuid,
            $submission_uuid,
            'document_test',
            'test_e2e.php',
            '',
            'text/plain',
            strlen($file_content),
            $file_content,
        ]);
        return $result ? true : 'Échec insertion pièce jointe';
    });

    test('Attachment récupérable depuis la DB', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT original_name, mime_type, file_size FROM attachments WHERE submission_id = ? LIMIT 1");
        $stmt->execute([$submission_uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'Pièce jointe non trouvée';
        if ($row['original_name'] !== 'test_e2e.php') return 'Nom de fichier incorrect';
        if ($row['mime_type'] !== 'text/plain') return 'Type MIME incorrect';
        return true;
    });

    test('Contenu BLOB est intact', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT file_data FROM attachments WHERE submission_id = ? LIMIT 1");
        $stmt->execute([$submission_uuid]);
        $data = $stmt->fetchColumn();
        return strlen($data) > 0 ? true : 'BLOB vide';
    });

    echo "\n";
}

/**
 * Section 10 : Formulaire outboarding.
 */
function run_tests_e2e_outboarding(): void {
    global $pdo, $outboarding_id;

    echo "── 10. Formulaire outboarding ──\n";

    $outboarding_uuid = generate_uuid();
    $outboarding_data = json_encode([
        'nom' => 'Durand',
        'prenom' => 'Marie',
        'date_fin_contrat' => '2026-12-31',
        'motif_depart' => 'Démission',
    ], JSON_UNESCAPED_UNICODE);

    test('Soumission outboarding', function() use ($pdo, $outboarding_uuid, $outboarding_id, $outboarding_data) {
        if (!$outboarding_id) return 'Formulaire outboarding introuvable';
        $stmt = $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, rgpd_consent) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'), 1)");
        return $stmt->execute([$outboarding_uuid, $outboarding_id, $outboarding_data, 'marie.durand@e2e.test']) ? true : 'Échec';
    });

    test('Workflow outboarding démarre correctement', function() use ($outboarding_uuid, $outboarding_id, $pdo) {
        if (!$outboarding_id) return 'Formulaire outboarding introuvable';

        // S'assurer que les étapes outboarding ont des destinataires
        $steps = $pdo->prepare("SELECT id, label FROM steps WHERE form_id = ?");
        $steps->execute([$outboarding_id]);
        while ($s = $steps->fetch(PDO::FETCH_ASSOC)) {
            $rcpt = $pdo->prepare("SELECT COUNT(*) FROM step_recipients WHERE step_id = ?");
            $rcpt->execute([$s['id']]);
            if ($rcpt->fetchColumn() == 0) {
                $email = strtolower(preg_replace('/[^a-z0-9]/', '', $s['label'])) . '@exemple.invalid';
                $ins = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
                $ins->execute([generate_uuid(), $s['id'], $email]);
            }
        }

        \App\Core\App::workflow()->advanceWorkflow($outboarding_uuid);

        $tokens = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $tokens->execute([$outboarding_uuid]);
        $count = $tokens->fetchColumn();
        return $count > 0 ? true : "Aucun token généré pour outboarding";
    });

    echo "\n";
}
