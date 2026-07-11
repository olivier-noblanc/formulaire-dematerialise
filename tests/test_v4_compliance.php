<?php
/**
 * tests/test_v4_compliance.php — Phase 10 + Phase 11 + Phase 12
 *
 * Phase 10 : Consentement RGPD à la soumission (form.php sans/avec rgpd_consent)
 * Phase 11 : Rate limiting (rgpd_export limité à 5 requêtes/60s)
 * Phase 12 : Documentation avec captures d'écran (docs.php)
 *
 * Dépendances : test_bootstrap.php (assert_test, bold), tests/test_v4_helpers.php (api, http_request).
 */

declare(strict_types=1);

/**
 * Phase 10 : RGPD Consent (champ rgpd_consent requis à la soumission).
 */
function run_tests_v4_consent(): void {
    echo "\n" . bold("Phase 10 : Consentement RGPD à la soumission\n");

    // 10a. POST form.php sans rgpd_consent → échec validation
    $r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
        'nom' => 'NoConsent',
        'prenom' => 'Agent',
        'date_naissance' => '1990-01-01',
        'date_prise_poste' => '2026-11-01',
        'corps_grade' => 'Inspecteur',
        'type_arrivee' => 'Mutation',
        'affectation' => 'Service Test',
        'quotite' => '100%',
        'type_poste' => 'Fixe',
        'log_batiment_bureau' => 'Bat N 300',
        // PAS de rgpd_consent
    ], 'no.consent.agent');
    $no_consent_json = $r['json'] ?? [];
    assert_test('Soumission sans consentement RGPD échoue',
        !empty($no_consent_json['field_errors']) && isset($no_consent_json['field_errors']['rgpd_consent']),
        'Réponse: ' . substr($r['body'] ?? '', 0, 300));
    assert_test('Erreur sur rgpd_consent',
        isset($no_consent_json['field_errors']['rgpd_consent']),
        'Pas d\'erreur sur rgpd_consent');

    // 10b. POST form.php avec rgpd_consent=1 → succès
    $r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
        'nom' => 'WithConsent',
        'prenom' => 'Agent',
        'date_naissance' => '1990-01-01',
        'date_prise_poste' => '2026-12-01',
        'corps_grade' => 'Secrétaire',
        'type_arrivee' => 'Stage',
        'affectation' => 'Service Consenti',
        'quotite' => '80%',
        'type_poste' => 'Fixe',
        'log_batiment_bureau' => 'Bat C 400',
        'rgpd_consent' => '1',
    ], 'with.consent.agent');
    $consent_json = $r['json'] ?? [];
    $consent_submission_id = $consent_json['submission_id'] ?? 0;
    assert_test('Soumission avec consentement RGPD réussie',
        ($consent_json['success'] ?? false) === true,
        'Réponse: ' . substr($r['body'] ?? '', 0, 300));

    // 10c. Vérifier que rgpd_consent=1 dans la soumission
    if ($consent_submission_id > 0) {
        $r = api('submission', ['submission_id' => $consent_submission_id]);
        $sub_detail = $r['json'] ?? [];
        assert_test('Colonne rgpd_consent = 1 dans la soumission',
            ($sub_detail['rgpd_consent'] ?? 0) == 1,
            'rgpd_consent: ' . ($sub_detail['rgpd_consent'] ?? 'null'));
    } else {
        assert_test('Colonne rgpd_consent = 1 (prérequis)', false, 'Pas de submission_id');
    }
}

/**
 * Phase 11 : Rate Limiting (rgpd_export limité à 5 requêtes/60s).
 */
function run_tests_v4_rate_limit(): void {
    echo "\n" . bold("Phase 11 : Rate Limiting\n");

    // 11a. Nettoyer la table rate_limits pour ce test
    // On ne peut pas le faire directement, mais on peut tester avec un endpoint qui utilise rate_limit_check
    // L'export RGPD utilise rate_limit_check('rgpd_export', 5, 60)
    // Faisons 6 exports rapides — le 6ème devrait être bloqué

    // D'abord créer un agent avec des données
    $r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
        'nom' => 'RateLimit',
        'prenom' => 'Test',
        'date_naissance' => '1990-01-01',
        'date_prise_poste' => '2027-01-01',
        'corps_grade' => 'Attaché',
        'type_arrivee' => 'Mutation',
        'affectation' => 'Service Rate',
        'quotite' => '100%',
        'type_poste' => 'Fixe',
        'log_batiment_bureau' => 'Bat RL 500',
        'rgpd_consent' => '1',
    ], 'ratelimit.agent');

    // Envoyer 6 requêtes d'export rapides
    $blocked = false;
    for ($i = 1; $i <= 6; $i++) {
        $r = http_request('POST', 'rgpd.php', [], [
            'action'       => 'export_user',
            'export_email' => 'ratelimit.agent@dreets.gouv.fr',
        ], 'test.agent');

        $body = $r['body'] ?? '';
        if (strpos($body, 'Trop de demandes') !== false ||
            strpos($body, 'patienter') !== false ||
            strpos($body, 'rate limit') !== false) {
            $blocked = true;
        }
    }
    assert_test('Rate limiting bloque après 5 exports', $blocked,
        'Aucun blocage après 6 requêtes rapides');

    // 11b. Vérifier que rate_limit_check retourne false quand la limite est atteinte
    // via le comportement observable (message d'erreur dans la page)
    assert_test('Rate limit check fonctionne (observable)', $blocked,
        'Le mécanisme de rate limiting ne semble pas actif');
}

/**
 * Phase 12 : Documentation avec captures d'écran.
 */
function run_tests_v4_documentation(): void {
    echo "\n" . bold("Phase 12 : Documentation avec captures d'écran\n");

    // 12a. GET docs.php → page de documentation
    $r = http_request('GET', 'docs.php', [], [], 'test.agent');
    assert_test('docs.php retourne 200', $r['http_code'] === 200,
        'Code: ' . $r['http_code']);

    $docs_body = $r['body'] ?? '';

    // 12b. Vérifier le contenu de la documentation
    assert_test('docs.php contient "Documentation"',
        strpos($docs_body, 'Documentation') !== false || strpos($docs_body, 'documentation') !== false,
        'Pas de titre Documentation');

    // 12c. Vérifier les captures d'écran référencées
    $screenshot_patterns = [
        'docs/screenshots/01_index_agent.png',
        'docs/screenshots/03_form_onboarding.png',
        'docs/screenshots/05_my_submissions.png',
        'docs/screenshots/15_validate.png',
    ];

    $screenshot_count = 0;
    foreach ($screenshot_patterns as $pattern) {
        if (strpos($docs_body, $pattern) !== false) {
            $screenshot_count++;
        }
    }
    assert_test('Captures d\'écran référencées (' . $screenshot_count . '/' . count($screenshot_patterns) . ')',
        $screenshot_count >= 2,
        'Moins de 2 captures d\'écran trouvées');

    // 12d. Vérifier les sections v4 dans la documentation
    $v4_doc_keywords = ['RGPD', 'rgpd', 'santé', 'Santé', 'statistique', 'Statistique', 'rate limit', 'consentement'];
    $found_keywords = 0;
    foreach ($v4_doc_keywords as $keyword) {
        if (stripos($docs_body, $keyword) !== false) {
            $found_keywords++;
        }
    }
    assert_test('Documentation mentionne les fonctionnalités v4 (' . $found_keywords . '/' . count($v4_doc_keywords) . ')',
        $found_keywords >= 3,
        'Moins de 3 mots-clés v4 trouvés dans la doc');

    // 12e. Vérifier la section mentions légales RGPD dans la doc
    assert_test('Documentation contient mentions légales RGPD',
        strpos($docs_body, 'mentions légales') !== false || strpos($docs_body, 'Mentions légales') !== false,
        'Section mentions légales non trouvée');
}
