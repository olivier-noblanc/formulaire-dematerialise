<?php
/**
 * tests/test_unit_nav_utils.php — Sections 9+10+11 : Navigation + Version + Utilitaires supplémentaires
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Sections 9+10+11 : Navigation + Version + Utilitaires supplémentaires
 */
function run_tests_unit_nav_utils(): void {
echo "── 9. Navigation ──\n";

test('render_nav() contient la sidebar', function() {
    $html = render_nav('accueil');
    return strpos($html, 'sidebar') !== false ? true : 'Pas de sidebar';
});

test('render_nav() contient les liens de navigation', function() {
    $html = render_nav('accueil');
    return strpos($html, 'index.php') !== false && strpos($html, 'my_submissions.php') !== false ? true : 'Liens manquants';
});

test('render_nav() marque la page active', function() {
    $html = render_nav('accueil');
    return strpos($html, 'active') !== false ? true : 'Pas de classe active';
});

test('render_nav() pour admin contient les liens admin', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $admin_email = get_admin_email();
    $_SERVER['HTTP_X_TEST_USER'] = $admin_email;
    $html = render_nav('forms');
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return strpos($html, 'index.php?p=admin_forms') !== false ? true : 'Liens admin manquants';
});

test('render_footer() contient la version', function() {
    $html = render_footer();
    $version = get_latest_version();
    return strpos($html, $version) !== false ? true : 'Version manquante dans footer';
});

test('render_footer() contient le nom de l\'app', function() {
    $html = render_footer();
    $app_name = get_app_name();
    return strpos($html, $app_name) !== false ? true : 'Nom d\'app manquant dans footer';
});

test('render_footer() contient la balise footer', function() {
    $html = render_footer();
    return strpos($html, '<footer>') !== false ? true : 'Pas de <footer>';
});

test('render_favicon() contient <link rel="icon">', function() {
    $html = render_favicon();
    return strpos($html, '<link rel="icon"') !== false ? true : 'Pas de favicon link: ' . $html;
});

test('render_favicon() contient data:image/svg+xml', function() {
    $html = render_favicon();
    return strpos($html, 'data:image/svg+xml') !== false ? true : 'Pas de SVG favicon: ' . $html;
});

echo "\n";

// ═══════════════════════════════════════════════════
// 10. VERSION & CONFIG
// ═══════════════════════════════════════════════════
echo "── 10. Version & configuration ──\n";

test('get_latest_version() lit depuis CHANGELOG.md', function() {
    $version = get_latest_version();
    return preg_match('/^\d+\.\d+\.\d+$/', $version) ? true : "Format invalide: $version";
});

test('get_latest_version() n\'est pas 0.0.0 (CHANGELOG existe)', function() {
    $version = get_latest_version();
    return $version !== '0.0.0' ? true : 'Version 0.0.0 — CHANGELOG.md peut-être manquant';
});

test('get_app_name() retourne une chaîne non vide', function() {
    $name = get_app_name();
    return !empty($name) && is_string($name) ? true : 'Nom vide ou non-string';
});

test('get_app_name() retourne CircuitDémat par défaut', function() {
    $name = get_app_name();
    return $name === 'CircuitDémat' ? true : "Got: $name";
});

test('get_admin_email() retourne un email valide', function() {
    $email = get_admin_email();
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? true : "Email invalide: $email";
});

test('BASE_URL est définie', function() {
    return defined('BASE_URL') ? true : 'BASE_URL non définie';
});

test('DB_PATH est définie', function() {
    return defined('DB_PATH') ? true : 'DB_PATH non définie';
});

test('SETTINGS_DEFAULTS est défini', function() {
    return defined('SETTINGS_DEFAULTS') ? true : 'SETTINGS_DEFAULTS non défini';
});

test('TEST_MODE est true en mode test', function() {
    return TEST_MODE === true ? true : 'TEST_MODE n\'est pas true';
});

echo "\n";

// ═══════════════════════════════════════════════════
// 11. FONCTIONS UTILITAIRES SUPPLÉMENTAIRES
// ═══════════════════════════════════════════════════
echo "── 11. Utilitaires supplémentaires ──\n";

test('format_file_size() octets', function() {
    $result = format_file_size(500);
    return strpos($result, 'octets') !== false ? true : "Got: $result";
});

test('format_file_size() kilo-octets', function() {
    $result = format_file_size(2048);
    return strpos($result, 'Ko') !== false ? true : "Got: $result";
});

test('format_file_size() méga-octets', function() {
    $result = format_file_size(5 * 1024 * 1024);
    return strpos($result, 'Mo') !== false ? true : "Got: $result";
});

test('get_file_icon() PDF', function() {
    $icon = get_file_icon('application/pdf');
    return !empty($icon) ? true : 'Icône vide pour PDF';
});

test('get_file_icon() image', function() {
    $icon = get_file_icon('image/jpeg');
    return !empty($icon) ? true : 'Icône vide pour image';
});

test('get_file_icon() type inconnu', function() {
    $icon = get_file_icon('application/unknown');
    return !empty($icon) ? true : 'Icône vide pour type inconnu';
});

test('parse_options_input() JSON existant', function() {
    $result = parse_options_input('["A","B"]');
    return $result === '["A","B"]' ? true : "Got: $result";
});

test('parse_options_input() lignes → JSON', function() {
    $result = parse_options_input("Option A\nOption B");
    $decoded = json_decode($result, true);
    return $decoded && count($decoded) === 2 ? true : "Got: $result";
});

test('parse_options_input() chaîne vide retourne null', function() {
    $result = parse_options_input('');
    return $result === null ? true : "Got: " . var_export($result, true);
});

test('resolve_dynamic_recipient() email simple inchangé', function() {
    $result = resolve_dynamic_recipient('test@dreets.gouv.fr', []);
    return $result === 'test@dreets.gouv.fr' ? true : "Got: $result";
});

test('resolve_dynamic_recipient() référence dynamique résolue', function() {
    $result = resolve_dynamic_recipient('{{email_manager}}', ['email_manager' => 'manager@dreets.gouv.fr']);
    return $result === 'manager@dreets.gouv.fr' ? true : "Got: $result";
});

test('resolve_dynamic_recipient() référence non résolue', function() {
    $result = resolve_dynamic_recipient('{{champ_inconnu}}', []);
    return $result === '{{champ_inconnu}}' ? true : "Got: $result";
});

test('is_form_owner() avec email non-propriétaire', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $form_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$form_id) return 'Pas de formulaire onboarding';
    $result = is_form_owner($form_id, 'nobody@dreets.gouv.fr');
    return $result === false ? true : 'Non-propriétaire détecté comme propriétaire';
});

test('get_form_owners() retourne un tableau', function() {
    $pdo = \App\Core\App::db()->getPdo();
    $form_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$form_id) return 'Pas de formulaire onboarding';
    $owners = get_form_owners($form_id);
    return is_array($owners) ? true : 'Pas un tableau';
});

test('get_owned_forms() retourne un tableau', function() {
    $forms = get_owned_forms('nobody@dreets.gouv.fr');
    return is_array($forms) ? true : 'Pas un tableau';
});

test('verify_email() format invalide', function() {
    $result = verify_email('not-an-email');
    return $result['ok'] === false ? true : 'Email invalide accepté';
});

test('verify_email() mode none = ok', function() {
    $result = verify_email('valid@dreets.gouv.fr');
    return $result['ok'] === true ? true : 'Email valide rejeté en mode none';
});

test('get_stats_by_period() retourne un tableau', function() {
    $result = get_stats_by_period('month', 5);
    return is_array($result) ? true : 'Pas un tableau';
});

test('get_tokens_for_submission() retourne un tableau', function() {
    $result = get_tokens_for_submission('00000000-0000-4000-8000-000000000000');
    return is_array($result) ? true : 'Pas un tableau';
});

test('send_mail() en mode test intercepte le mail', function() {
    reset_test_mails();
    $result = send_mail('test@dreets.gouv.fr', 'Test subject', '<p>Test body</p>');
    $mails = get_test_mails();
    return $result === true && count($mails) > 0 ? true : 'Mail non intercepté en mode test';
});

test('send_mail() intercepté contient les bonnes données', function() {
    reset_test_mails();
    send_mail('dest@dreets.gouv.fr', 'Mon sujet', '<p>Mon corps</p>');
    $mails = get_test_mails();
    $last = end($mails);
    return $last['to'] === 'dest@dreets.gouv.fr' && $last['subject'] === 'Mon sujet' ? true : 'Données du mail incorrectes';
});

test('get_attachments() retourne un tableau', function() {
    $result = get_attachments('00000000-0000-4000-8000-000000000000');
    return is_array($result) ? true : 'Pas un tableau';
});

test('get_attachment_by_id() avec ID invalide retourne null', function() {
    $result = get_attachment_by_id('00000000-0000-4000-8000-000000000000');
    return $result === null ? true : 'Pièce jointe trouvée pour ID inexistant';
});

test('build_mail_html() génère du HTML', function() {
    $submission = [
        'data' => json_encode(['nom' => 'Test', 'prenom' => 'Agent']),
        'form_label' => 'Test Form',
    ];
    $html = build_mail_html($submission, 'Étape 1', 'abc123token');
    return strpos($html, 'DOCTYPE') !== false && strpos($html, 'Test Form') !== false ? true : 'HTML manquant';
});

echo "\n";
}
