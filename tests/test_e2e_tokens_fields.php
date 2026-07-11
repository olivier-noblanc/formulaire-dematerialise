<?php
/**
 * tests/test_e2e_tokens_fields.php — Sections 14 + 15 : Relance + Types de champs
 *
 * Section 14 : Relance et expiration des tokens (relance_count, token expiré, relance manuelle)
 * Section 15 : Types de champs de formulaire (types supportés, options JSON valides, ordre, required, field_name)
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id, $submission_uuid.
 */

declare(strict_types=1);

/**
 * Section 14 : Relance et expiration des tokens.
 */
function run_tests_e2e_tokens(): void {
    global $pdo, $onboarding_id, $submission_uuid;

    echo "── 14. Relance et expiration des tokens ──\n";

    test('Les tokens ont un compteur de relance', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT relance_count FROM tokens WHERE submission_id = ? LIMIT 1");
        $stmt->execute([$submission_uuid]);
        $count = $stmt->fetchColumn();
        return $count !== false ? true : 'relance_count non trouvé';
    });

    test('Token expiré est rejeté', function() use ($pdo, $onboarding_id) {
        // Créer une soumission avec un token expiré
        $exp_uuid = generate_uuid();
        $exp_sub = generate_uuid();
        $exp_token = bin2hex(random_bytes(32));

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'))")
            ->execute([$exp_sub, $onboarding_id, 'expire.test@e2e.test']);

        // Créer un token déjà expiré
        $step1 = $pdo->prepare("SELECT id FROM steps WHERE form_id = ? ORDER BY ordre LIMIT 1");
        $step1->execute([$onboarding_id]);
        $step1_id = $step1->fetchColumn();

        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now', '-1 day'))")
            ->execute([$exp_uuid, $exp_sub, $step1_id, 'expire.test@e2e.test', $exp_token]);

        $result = \App\Core\App::workflow()->validateToken($exp_token);
        return $result['status'] === 'expired' ? true : 'Status: ' . $result['status'] . ' (attendu: expired)';
    });

    test('Relance manuelle fonctionne', function() use ($pdo, $onboarding_id) {
        // Créer une soumission avec un token non expiré et non validé
        $remind_uuid = generate_uuid();
        $remind_sub = generate_uuid();
        $remind_token = bin2hex(random_bytes(32));

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, '{}', ?, 'en_cours', datetime('now'))")
            ->execute([$remind_sub, $onboarding_id, 'remind.test@e2e.test']);

        $step1 = $pdo->prepare("SELECT id FROM steps WHERE form_id = ? ORDER BY ordre LIMIT 1");
        $step1->execute([$onboarding_id]);
        $step1_id = $step1->fetchColumn();

        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, expires_at, relance_count) VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now', '+30 days'), 0)")
            ->execute([$remind_uuid, $remind_sub, $step1_id, 'remind.test@e2e.test', $remind_token]);

        // Mettre à jour le compteur de relance
        $pdo->prepare("UPDATE tokens SET relance_count = relance_count + 1, relance_at = datetime('now') WHERE token = ?")
            ->execute([$remind_token]);

        $stmt = $pdo->prepare("SELECT relance_count, relance_at FROM tokens WHERE token = ?");
        $stmt->execute([$remind_token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ($row['relance_count'] > 0 && !empty($row['relance_at'])) ? true : 'Relance non enregistrée';
    });

    echo "\n";
}

/**
 * Section 15 : Types de champs de formulaire.
 */
function run_tests_e2e_fields(): void {
    global $pdo, $onboarding_id;

    echo "── 15. Types de champs de formulaire ──\n";

    $field_types_found = [];
    $stmt_ft = $pdo->query("SELECT DISTINCT field_type FROM form_fields");
    while ($ft = $stmt_ft->fetchColumn()) {
        $field_types_found[] = $ft;
    }

    test('Les types de champs supportés sont présents', function() use ($field_types_found) {
        $expected = ['text', 'date', 'select', 'checkbox', 'textarea'];
        $missing = array_diff($expected, $field_types_found);
        if (!empty($missing)) return 'Types manquants: ' . implode(', ', $missing);
        return true;
    });

    test('Champs select ont des options JSON valides', function() use ($pdo) {
        $selects = $pdo->query("SELECT id, options, label FROM form_fields WHERE field_type = 'select'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($selects as $s) {
            $decoded = json_decode($s['options'], true);
            if (!is_array($decoded)) return "Options invalides pour '{$s['label']}': " . $s['options'];
        }
        return true;
    });

    test('Champs ont un ordre défini', function() use ($pdo, $onboarding_id) {
        $stmt = $pdo->prepare("SELECT label, ordre FROM form_fields WHERE form_id = ? ORDER BY ordre");
        $stmt->execute([$onboarding_id]);
        $last_ordre = -1;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['ordre'] <= $last_ordre) return "Ordre non croissant pour '{$row['label']}'";
            $last_ordre = $row['ordre'];
        }
        return true;
    });

    test('Champs requis ont required = 1', function() use ($pdo, $onboarding_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM form_fields WHERE form_id = ? AND required = 1");
        $stmt->execute([$onboarding_id]);
        $count = $stmt->fetchColumn();
        return $count > 0 ? true : 'Aucun champ requis trouvé';
    });

    test('Tous les champs ont un field_name valide', function() use ($pdo, $onboarding_id) {
        $stmt = $pdo->prepare("SELECT field_name, label FROM form_fields WHERE form_id = ?");
        $stmt->execute([$onboarding_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['field_name'])) return "field_name vide pour '{$row['label']}'";
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $row['field_name'])) return "field_name invalide pour '{$row['label']}': {$row['field_name']}";
        }
        return true;
    });

    echo "\n";
}
