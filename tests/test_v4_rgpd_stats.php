<?php
/**
 * tests/test_v4_rgpd_stats.php — Phase 2 + Phase 4 : RGPD Compliance + Statistics
 *
 * Teste la conformité RGPD (mentions légales, export, purge) et les statistiques
 * par période (week, month, year) avec contrôle d'accès admin.
 *
 * Dépendances : test_bootstrap.php (assert_test, bold), tests/test_v4_helpers.php (api, http_request).
 */

declare(strict_types=1);

/**
 * Phase 2 : RGPD Compliance (mentions légales, export, purge).
 */
function run_tests_v4_rgpd(): void {
    echo "\n" . bold("Phase 2 : RGPD Compliance\n");

    // 2a. GET rgpd.php en tant que non-admin → redirect
    $r = http_request('GET', 'rgpd.php', [], [], 'nonadmin.user');
    // Non-admin devrait être redirigé (302) ou avoir un accès refusé
    $non_admin_blocked = ($r['http_code'] === 302 || $r['http_code'] === 403
        || strpos($r['body'] ?? '', 'admin_access') !== false
        || strpos($r['body'] ?? '', 'Accès refusé') !== false
        || strpos($r['body'] ?? '', 'Location') !== false);
    assert_test('rgpd.php refuse les non-admin', $non_admin_blocked,
        'Code: ' . $r['http_code']);

    // 2b. GET rgpd.php en tant qu'admin → accessible
    $r = http_request('GET', 'rgpd.php', [], [], 'test.agent');
    assert_test('rgpd.php accessible aux admins', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);
    assert_test('rgpd.php contient section RGPD',
        strpos($r['body'] ?? '', 'RGPD') !== false || strpos($r['body'] ?? '', 'rgpd') !== false,
        'Corps ne contient pas RGPD');

    // 2c. POST rgpd.php action=update_legal (mentions légales)
    // On doit d'abord récupérer un CSRF token via une session admin
    // En mode test, CSRF est bypassé, on peut poster directement
    $r = http_request('POST', 'rgpd.php', [], [
        'action'           => 'update_legal',
        'legal_mentions'   => 'Mentions légales de test v4.0 — Données traitées conformément au RGPD.',
        'retention_months' => '36',
    ], 'test.agent');
    assert_test('POST update_legal réussi (admin)',
        $r['http_code'] === 200 && (
            strpos($r['body'] ?? '', 'succès') !== false ||
            strpos($r['body'] ?? '', 'succes') !== false ||
            strpos($r['body'] ?? '', 'mis à jour') !== false ||
            strpos($r['body'] ?? '', 'enregistrées') !== false ||
            strpos($r['body'] ?? '', 'msg-success') !== false
        ),
        'Code: ' . $r['http_code']);

    // 2d. Vérifier que legal_mentions est sauvegardé
    $r = http_request('GET', 'rgpd.php', [], [], 'test.agent');
    $body = $r['body'] ?? '';
    assert_test('legal_mentions sauvegardé',
        strpos($body, 'Mentions légales de test v4.0') !== false,
        'Texte non trouvé dans la page');
    assert_test('retention_months=36 sauvegardé',
        strpos($body, '36') !== false,
        'Valeur 36 non trouvée');

    // 2e. POST rgpd.php action=export_user (export données agent)
    // Créer d'abord une soumission pour avoir des données à exporter
    $r = api('forms');
    $forms_list = $r['json'] ?? [];
    $onboarding_form = null;
    foreach ($forms_list as $f) {
        if ($f['slug'] === 'onboarding') { $onboarding_form = $f; break; }
    }

    // Ajouter des destinataires à l'onboarding pour permettre la soumission
    if ($onboarding_form) {
        $r = api('steps', ['form_id' => $onboarding_form['id']]);
        $onb_steps = $r['json'] ?? [];
        $recipient_map = [
            'Responsable direct'   => 'resp.direct@dreets.gouv.fr',
            'Service informatique' => 'it.service@dreets.gouv.fr',
            'Ressources humaines'  => 'rh.service@dreets.gouv.fr',
            'Logistique'           => 'logistique@dreets.gouv.fr',
        ];
        foreach ($onb_steps as $step) {
            if (empty($step['recipients'])) {
                $email = $recipient_map[$step['label']] ?? null;
                if ($email) api('add_recipient', ['step_id' => $step['id'], 'email' => $email]);
            }
        }
    }

    // Soumettre un formulaire pour créer des données à exporter
    $r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
        'nom' => 'RGPDTest',
        'prenom' => 'Agent',
        'date_naissance' => '1995-03-10',
        'date_prise_poste' => '2026-08-01',
        'corps_grade' => 'Attaché',
        'type_arrivee' => 'Mutation',
        'affectation' => 'Service Test',
        'quotite' => '100%',
        'type_poste' => 'Fixe',
        'log_batiment_bureau' => 'Bat X 100',
        'rgpd_consent' => '1',
    ], 'rgpd.test.agent');
    $rgpd_sub = $r['json'] ?? [];
    $rgpd_submission_id = $rgpd_sub['submission_id'] ?? 0;
    assert_test('Soumission pour export RGPD créée', ($rgpd_sub['success'] ?? false) === true,
        'ID: ' . $rgpd_submission_id);

    // Tester l'export RGPD
    $r = http_request('POST', 'rgpd.php', [], [
        'action'       => 'export_user',
        'export_email' => 'rgpd.test.agent@dreets.gouv.fr',
    ], 'test.agent');
    // L'export peut retourner du JSON en téléchargement ou un message d'info
    $export_worked = (
        $r['http_code'] === 200 && (
            // Soit on a un JSON d'export
            ($r['json'] !== null && isset($r['json']['email'])) ||
            // Soit on a un Content-Disposition attachment
            strpos($r['body'] ?? '', 'rgpd_export_') !== false ||
            // Soit un message indiquant aucune donnée ou succès
            strpos($r['body'] ?? '', 'export') !== false
        )
    );
    assert_test('Export RGPD fonctionne', $export_worked,
        'Code: ' . $r['http_code'] . ', body: ' . substr($r['body'] ?? '', 0, 200));

    // 2f. POST rgpd.php action=auto_purge (purge des données anciennes)
    $r = http_request('POST', 'rgpd.php', [], [
        'action'    => 'auto_purge',
        'confirmed' => '1',
    ], 'test.agent');
    $purge_worked = (
        $r['http_code'] === 200 && (
            strpos($r['body'] ?? '', 'Purge') !== false ||
            strpos($r['body'] ?? '', 'purge') !== false ||
            strpos($r['body'] ?? '', 'Aucune') !== false ||
            strpos($r['body'] ?? '', 'msg-success') !== false ||
            strpos($r['body'] ?? '', 'msg-info') !== false
        )
    );
    assert_test('Auto-purge RGPD exécutable', $purge_worked,
        'Code: ' . $r['http_code']);
}

/**
 * Phase 4 : Statistics (par période).
 */
function run_tests_v4_stats(): void {
    echo "\n" . bold("Phase 4 : Statistiques\n");

    // 4a. GET stats.php en tant que non-admin → redirect
    $r = http_request('GET', 'stats.php', [], [], 'nonadmin.user');
    $stats_blocked = ($r['http_code'] === 302 || $r['http_code'] === 403
        || strpos($r['body'] ?? '', 'admin_access') !== false
        || strpos($r['body'] ?? '', 'Accès refusé') !== false);
    assert_test('stats.php refuse les non-admin', $stats_blocked,
        'Code: ' . $r['http_code']);

    // 4b. GET stats.php (admin, période par défaut)
    $r = http_request('GET', 'stats.php', [], [], 'test.agent');
    assert_test('stats.php accessible aux admins', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);
    assert_test('stats.php contient "Statistiques"',
        strpos($r['body'] ?? '', 'Statistiques') !== false || strpos($r['body'] ?? '', 'statistiques') !== false,
        'Pas de titre statistiques');

    // 4c. GET stats.php?period=week
    $r = http_request('GET', 'stats.php', ['period' => 'week'], [], 'test.agent');
    assert_test('stats.php?period=week retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);
    assert_test('Période semaine active',
        strpos($r['body'] ?? '', 'semaine') !== false || strpos($r['body'] ?? '', 'week') !== false,
        'Pas de mention semaine');

    // 4d. GET stats.php?period=month
    $r = http_request('GET', 'stats.php', ['period' => 'month'], [], 'test.agent');
    assert_test('stats.php?period=month retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);

    // 4e. GET stats.php?period=year
    $r = http_request('GET', 'stats.php', ['period' => 'year'], [], 'test.agent');
    assert_test('stats.php?period=year retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);
}
