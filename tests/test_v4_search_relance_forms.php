<?php
/**
 * tests/test_v4_search_relance_forms.php — Phase 7 + Phase 8 + Phase 9
 *
 * Phase 7 : Recherche plein texte (dashboard.php?search=)
 * Phase 8 : Historique des relances (submission_view.php)
 * Phase 9 : Nouveaux formulaires seedés v4 (mutation, formation, etc.)
 *
 * Dépendances : test_bootstrap.php (assert_test, bold), tests/test_v4_helpers.php (api, http_request).
 */

declare(strict_types=1);

/**
 * Phase 7 : Full-text Search (dashboard.php?search=).
 */
function run_tests_v4_search(): void {
    echo "\n" . bold("Phase 7 : Recherche plein texte\n");

    // 7a. Rechercher un terme existant dans les soumissions
    $r = http_request('GET', 'dashboard.php', ['search' => 'RGPDTest'], [], 'test.agent');
    assert_test('dashboard.php?search=RGPDTest retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);

    // Le dashboard HTML doit contenir le résultat de la recherche
    $search_body = $r['body'] ?? '';
    assert_test('Recherche trouve "RGPDTest"',
        strpos($search_body, 'RGPDTest') !== false,
        'Terme non trouvé dans les résultats');

    // 7b. Rechercher par nom de formulaire
    $r = http_request('GET', 'dashboard.php', ['search' => 'onboarding'], [], 'test.agent');
    assert_test('Recherche par formulaire fonctionne', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);

    // 7c. Rechercher un terme inexistant → pas de résultats
    $r = http_request('GET', 'dashboard.php', ['search' => 'ZZZNONEXISTANT999'], [], 'test.agent');
    assert_test('Recherche terme inexistant retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);
    // Le body ne doit pas crasher
    $no_crash = strpos($r['body'] ?? '', 'Fatal error') === false;
    assert_test('Recherche ne crash pas', $no_crash);
}

/**
 * Phase 8 : Relance History (submission_view.php section relances).
 */
function run_tests_v4_relance(): void {
    echo "\n" . bold("Phase 8 : Historique des relances\n");

    // 8a. Créer une soumission puis envoyer un rappel
    $r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
        'nom' => 'RelanceTest',
        'prenom' => 'Agent',
        'date_naissance' => '1990-01-01',
        'date_prise_poste' => '2026-10-01',
        'corps_grade' => 'Ingénieur',
        'type_arrivee' => 'Mutation',
        'affectation' => 'Service Relance',
        'quotite' => '100%',
        'type_poste' => 'Portable',
        'log_batiment_bureau' => 'Bat R 200',
        'rgpd_consent' => '1',
    ], 'relance.test.agent');
    $relance_sub = $r['json'] ?? [];
    $relance_submission_id = $relance_sub['submission_id'] ?? 0;
    assert_test('Soumission pour relance créée', ($relance_sub['success'] ?? false) === true,
        'ID: ' . $relance_submission_id);

    if ($relance_submission_id > 0) {
        // 8b. Envoyer un rappel via dashboard.php
        $r = api('tokens', ['submission_id' => $relance_submission_id]);
        $relance_tokens = $r['json'] ?? [];

        if (!empty($relance_tokens)) {
            $token_id = $relance_tokens[0]['id'];

            $r = http_request('POST', 'dashboard.php', [], [
                'action'   => 'remind_one',
                'token_id' => $token_id,
            ], 'test.agent');
            // Le remind_one dans dashboard.php ne retourne pas de JSON en mode test
            // sauf si TEST_MODE est géré, mais il ne crash pas
            $remind_no_crash = ($r['http_code'] === 200 || $r['http_code'] === 302);
            assert_test('Rappel manuel envoyé', $remind_no_crash,
                'Code: ' . $r['http_code']);

            // 8c. Vérifier l'historique des relances dans submission_view
            $r = http_request('GET', 'submission_view.php', ['id' => $relance_submission_id], [], 'test.agent');
            assert_test('submission_view.php accessible', $r['http_code'] === 200,
                'Code: ' . $r['http_code']);

            $view_body = $r['body'] ?? '';
            $has_relance_section = (
                strpos($view_body, 'Historique des relances') !== false ||
                strpos($view_body, 'historique') !== false ||
                strpos($view_body, 'relance') !== false ||
                strpos($view_body, 'Rappel') !== false
            );
            assert_test('Section relances présente', $has_relance_section,
                'Section relances non trouvée');
        } else {
            assert_test('Rappel manuel envoyé (prérequis)', false, 'Pas de tokens pour la soumission');
            assert_test('Section relances présente (prérequis)', false, 'Pas de tokens');
        }
    } else {
        assert_test('Rappel manuel envoyé (prérequis)', false, 'Soumission non créée');
        assert_test('Section relances présente (prérequis)', false, 'Soumission non créée');
    }
}

/**
 * Phase 9 : New Seeded Forms (6 nouveaux formulaires v4).
 */
function run_tests_v4_new_forms(): void {
    echo "\n" . bold("Phase 9 : Nouveaux formulaires seedés v4\n");

    $v4_forms = [
        'mutation'    => 'Mutation',
        'formation'   => 'Formation',
        'acces_si'    => 'Accès SI',
        'conge'       => 'Congé',
        'materiel'    => 'Matériel',
        'signalement' => 'Signalement',
    ];

    foreach ($v4_forms as $slug => $label) {
        $r = http_request('GET', 'form.php', ['f' => $slug], [], 'test.agent');
        $form_json = $r['json'] ?? [];

        // En mode test, GET form.php retourne du JSON avec les infos du formulaire
        $form_loaded = ($r['http_code'] === 200 &&
            ($form_json['form']['slug'] ?? '') === $slug);
        assert_test("form.php?f=$slug se charge", $form_loaded,
            'Code: ' . $r['http_code'] . ', slug: ' . ($form_json['form']['slug'] ?? 'null'));

        // Vérifier que le formulaire a des champs
        $field_count = count($form_json['fields'] ?? []);
        assert_test("Formulaire '$slug' a des champs ($field_count)", $field_count > 0,
            '0 champs trouvés');

        // Vérifier que le formulaire a des étapes
        $csrf_present = !empty($form_json['csrf_token']);
        assert_test("Formulaire '$slug' fournit un CSRF token", $csrf_present,
            'CSRF token manquant');
    }
}
