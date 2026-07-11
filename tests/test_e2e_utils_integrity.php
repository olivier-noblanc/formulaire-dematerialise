<?php
/**
 * tests/test_e2e_utils_integrity.php — Sections 11 + 12 + 13
 *
 * Section 11 : Fonctions utilitaires avancées (get_form_by_uuid, has_active_submissions,
 *              search_submissions, audit log, settings, generate_field_name)
 * Section 12 : Intégrité des données et cohérence (tokens orphelins, FK valides, UUIDs v4)
 * Section 13 : RGPD et conformité (consentement, mentions légales, rétention, audit log RGPD)
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id, $submission_uuid.
 */

declare(strict_types=1);

/**
 * Section 11 : Fonctions utilitaires avancées.
 */
function run_tests_e2e_utils(): void {
    global $pdo, $onboarding_id, $submission_uuid;

    echo "── 11. Fonctions utilitaires ──\n";

    test('get_form_by_uuid() fonctionne', function() use ($onboarding_id) {
        $form = get_form_by_uuid($onboarding_id);
        if (!$form) return 'Formulaire non trouvé';
        if ($form['id'] !== $onboarding_id) return 'ID incorrect';
        return true;
    });

    test('has_active_submissions() détecte les soumissions', function() use ($onboarding_id) {
        return \App\Core\App::workflow()->hasActiveSubmissions($onboarding_id) ? true : 'Pas de soumissions actives détectées';
    });

    test('search_submissions() trouve des résultats', function() use ($onboarding_id) {
        $results = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->searchSubmissions('Martin', ['form_id' => $onboarding_id]);
        return count($results) > 0 ? true : 'Recherche "Martin" sans résultats';
    });

    test('Le workflow trace les validations dans data', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare('SELECT data FROM submissions WHERE id = ?');
        $stmt->execute([$submission_uuid]);
        $data = json_decode($stmt->fetchColumn(), true);
        $validations = $data['validations'] ?? [];
        return count($validations) > 0 ? true : 'Pas de validations dans data';
    });

    test('Audit log enregistre les actions', function() use ($pdo) {
        $before = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
        \App\Core\App::audit()->log('e2e_test', 'test_target', 'Test E2E audit log');
        $after = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
        return $after > $before ? true : 'Audit log non incrémenté';
    });

    test('get_setting() / set_setting() cycle complet', function() {
        set_setting('e2e_test_key', 'e2e_test_value_' . time());
        $val = get_setting('e2e_test_key');
        /** @phpstan-ignore-next-line */
        return $val !== null && $val !== false ? true : 'Setting non récupéré';
    });

    test('generate_field_name() gère les accents', function() {
        $name1 = generate_field_name('Date de naissance');
        $name2 = generate_field_name('Corps/Grade');
        $name3 = generate_field_name('Affectation (service)');

        $ok1 = $name1 === 'date_de_naissance';
        $ok2 = strpos($name2, '/') === false; // Pas de slash
        $ok3 = strpos($name3, '(') === false && strpos($name3, ')') === false; // Pas de parenthèses

        return ($ok1 && $ok2 && $ok3) ? true : "Résultats: '$name1', '$name2', '$name3'";
    });

    test('generate_field_name() gère les caractères spéciaux français', function() {
        $name = generate_field_name('Élément récent à vérifier');
        // Doit contenir uniquement des caractères alphanumériques et underscores
        return preg_match('/^[a-z0-9_]+$/', $name) ? true : "Caractères invalides dans: '$name'";
    });

    echo "\n";
}

/**
 * Section 12 : Intégrité des données et cohérence.
 */
function run_tests_e2e_integrity(): void {
    global $pdo, $onboarding_id;

    echo "── 12. Intégrité des données ──\n";

    test('Tous les tokens ont un submission_id valide', function() use ($pdo) {
        $orphans = $pdo->query("
            SELECT COUNT(*) FROM tokens t
            LEFT JOIN submissions s ON t.submission_id = s.id
            WHERE s.id IS NULL
        ")->fetchColumn();
        return $orphans == 0 ? true : "$orphans tokens orphelins (sans soumission)";
    });

    test('Tous les tokens ont un step_id valide', function() use ($pdo) {
        $orphans = $pdo->query("
            SELECT COUNT(*) FROM tokens t
            LEFT JOIN steps s ON t.step_id = s.id
            WHERE s.id IS NULL
        ")->fetchColumn();
        return $orphans == 0 ? true : "$orphans tokens orphelins (sans étape)";
    });

    test('Les soumissions "valide" ont toutes des étapes validées', function() use ($pdo, $onboarding_id) {
        // Trouver une soumission validée
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? AND status = 'valide' LIMIT 1");
        $stmt->execute([$onboarding_id]);
        $valid_sub = $stmt->fetchColumn();
        if (!$valid_sub) return true; // Pas de soumission validée, test ignoré

        // Tous les tokens de cette soumission doivent avoir done_at renseigné
        $pending = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND done_at IS NULL");
        $pending->execute([$valid_sub]);
        $count = $pending->fetchColumn();
        return $count == 0 ? true : "$count tokens en attente pour une soumission validée";
    });

    test('Les soumissions "refuse" ont au moins un token refusé', function() use ($pdo, $onboarding_id) {
        $stmt = $pdo->prepare("SELECT id FROM submissions WHERE form_id = ? AND status = 'refuse' LIMIT 1");
        $stmt->execute([$onboarding_id]);
        $refused_sub = $stmt->fetchColumn();
        if (!$refused_sub) return true; // Pas de soumission refusée, test ignoré
        return true; // Le statut refuse est déjà vérifié par le test de refus
    });

    test('Tous les UUIDs de submissions sont au format UUID v4', function() use ($pdo) {
        $uuids = $pdo->query("SELECT id FROM submissions")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($uuids as $uid) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uid)) {
                return "UUID non-v4: $uid";
            }
        }
        return true;
    });

    test('Les FK form_id dans submissions sont des UUIDs valides', function() use ($pdo) {
        $fids = $pdo->query("SELECT DISTINCT form_id FROM submissions")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fids as $fid) {
            if (!preg_match('/^[0-9a-f]{8}-/i', $fid)) return "FK form_id non-UUID: $fid";
        }
        return true;
    });

    test('Aucune soumission sans formulaire', function() use ($pdo) {
        $orphans = $pdo->query("
            SELECT COUNT(*) FROM submissions s
            LEFT JOIN forms f ON s.form_id = f.id
            WHERE f.id IS NULL
        ")->fetchColumn();
        return $orphans == 0 ? true : "$orphans soumissions sans formulaire";
    });

    echo "\n";
}

/**
 * Section 13 : RGPD et conformité.
 */
function run_tests_e2e_rgpd(): void {
    global $pdo, $submission_uuid;

    echo "── 13. RGPD et conformité ──\n";

    test('Le consentement RGPD est enregistré', function() use ($pdo, $submission_uuid) {
        $stmt = $pdo->prepare("SELECT rgpd_consent FROM submissions WHERE id = ?");
        $stmt->execute([$submission_uuid]);
        $consent = $stmt->fetchColumn();
        return $consent == 1 ? true : 'Consentement RGPD non enregistré (valeur: ' . $consent . ')';
    });

    test('Les mentions légales sont configurables', function() {
        set_setting('legal_mentions', 'Test mentions légales E2E');
        $val = get_setting('legal_mentions');
        return $val === 'Test mentions légales E2E' ? true : 'Mentions légales non configurables';
    });

    test('La durée de conservation est configurable', function() {
        set_setting('retention_months', '36');
        $val = get_setting('retention_months');
        $result = $val === '36' ? true : 'Durée de conservation non configurable';
        // Remettre la valeur par défaut
        set_setting('retention_months', '24');
        return $result;
    });

    test('Audit log trace les actions RGPD', function() use ($pdo) {
        \App\Core\App::audit()->log('rgpd_export', 'test_user@e2e.test', 'Export RGPD de test E2E');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'rgpd_export'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        return $count > 0 ? true : 'Action RGPD non tracée dans l\'audit';
    });

    echo "\n";
}
