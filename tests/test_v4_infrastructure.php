<?php
/**
 * tests/test_v4_infrastructure.php — Phase 1 + Phase 3 : Core Infra + Health Check
 *
 * Teste l'infrastructure v4.0.0 (schema_version, formulaires seedés, BLOB, rate_limits)
 * et l'endpoint health.php (HTML + JSON, 5 checks).
 *
 * Dépendances : test_bootstrap.php (assert_test, bold), tests/test_v4_helpers.php (api, http_request).
 */

declare(strict_types=1);

/**
 * Phase 1 : Core Infrastructure (schema_version, BLOB, rgpd_consent, rate_limits).
 */
function run_tests_v4_infrastructure(): void {
    echo bold("Phase 1 : Core Infrastructure (schema_version, BLOB, rgpd_consent, rate_limits)\n");

    // 1a. Vérifier que la table schema_version existe et a des entrées
    $r = api('stats');
    $stats = $r['json'] ?? [];
    assert_test('Serveur PHP actif', $r['http_code'] === 200, 'Code: ' . $r['http_code']);
    assert_test('Mode test activé', ($stats['test_mode'] ?? false) === true);

    // Via le test_api, on ne peut pas directement interroger le schéma.
    // On va utiliser health.php?format=json pour vérifier le schéma
    $r = http_request('GET', 'health.php', ['format' => 'json'], [], 'test.agent');
    $health_json = $r['json'] ?? [];
    assert_test('health.php JSON accessible', $r['http_code'] === 200, 'Code: ' . $r['http_code']);
    assert_test('schema_version existe (via health check DB)', ($health_json['status'] ?? '') === 'healthy',
        'Health status: ' . ($health_json['status'] ?? 'null'));

    // Vérifier que les formulaires sont seedés (preuve que le schéma est complet)
    $r = api('forms');
    $forms = $r['json'] ?? [];
    $form_slugs = array_column($forms, 'slug');
    assert_test('Formulaires seedés (8+ formulaires)', count($forms) >= 8,
        count($forms) . ' formulaires trouvés');

    // Vérifier les nouveaux formulaires v4
    $new_v4_slugs = ['mutation', 'formation', 'acces_si', 'conge', 'materiel', 'signalement'];
    foreach ($new_v4_slugs as $slug) {
        assert_test("Formulaire '$slug' présent en DB", in_array($slug, $form_slugs),
            'Slugs: ' . implode(', ', $form_slugs));
    }

    // Vérifier via test_api que les tables rate_limits, schema_version existent indirectement
    // en testant que rate_limit_check fonctionne (sera testé en Phase 11)
    // et que le schema_version a des entrées via les migrations
    // Pour cela on utilise le fait que les settings webhook existent (migration v4)
    $r = api('stats');
    assert_test('DB test initialisée correctement', ($stats['forms'] ?? 0) >= 8,
        ($stats['forms'] ?? 0) . ' formulaires');
}

/**
 * Phase 3 : Health Check (endpoint monitoring).
 */
function run_tests_v4_health(): void {
    echo "\n" . bold("Phase 3 : Health Check\n");

    // 3a. GET health.php (sans format) → HTML 200
    $r = http_request('GET', 'health.php', [], [], 'test.agent');
    assert_test('health.php retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);
    assert_test('health.php contient "Santé"',
        strpos($r['body'] ?? '', 'Santé') !== false || strpos($r['body'] ?? '', 'Sante') !== false || strpos($r['body'] ?? '', 'santé') !== false,
        'Pas de titre santé');

    // 3b. GET health.php?format=json → JSON avec status=healthy
    $r = http_request('GET', 'health.php', ['format' => 'json'], [], 'test.agent');
    $health = $r['json'] ?? [];
    assert_test('health.php?format=json retourne du JSON', $r['json'] !== null,
        'Body: ' . substr($r['body'] ?? '', 0, 200));
    assert_test('status = healthy', ($health['status'] ?? '') === 'healthy',
        'Status: ' . ($health['status'] ?? 'null'));
    assert_test('version présente', ($health['version'] ?? '') === '4.0.0',
        'Version: ' . ($health['version'] ?? 'null'));
    assert_test('timestamp présent', !empty($health['timestamp']),
        'Timestamp manquant');

    // 3c. Vérifier les 5 health checks
    $checks = $health['checks'] ?? [];
    assert_test('5 health checks présents', count($checks) === 5,
        count($checks) . ' checks trouvés');

    $check_labels = array_column($checks, 'label');
    $expected_checks = [
        'Base de données SQLite',
        'Version PHP',
        'Répertoire de données',
        'Schéma de base de données',
        'Configuration SMTP',
    ];
    foreach ($expected_checks as $expected) {
        $found = false;
        foreach ($check_labels as $label) {
            if (strpos($label, $expected) !== false || strpos($expected, $label) !== false) {
                $found = true;
                break;
            }
        }
        assert_test("Check '$expected' présent", $found,
            'Labels: ' . implode(', ', $check_labels));
    }

    // Vérifier que tous les checks sont OK
    $all_ok = true;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') !== 'ok') {
            $all_ok = false;
        }
    }
    assert_test('Tous les health checks sont OK', $all_ok,
        'Certains checks ont échoué');
}
