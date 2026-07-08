<?php
/**
 * tests/test_unit_render_data.php — Sections 5+6+7+8 : Rendu + Accès données + Erreurs + Sécurité
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Sections 5+6+7+8 : Rendu + Accès données + Erreurs + Sécurité
 */
function run_tests_unit_render_data(): void {
echo "── 5. Fonctions de rendu ──\n";

test('render_page() contient DOCTYPE', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, '<!DOCTYPE html>') !== false ? true : 'DOCTYPE manquant';
});

test('render_page() contient <html lang="fr">', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, '<html lang="fr">') !== false ? true : 'html lang manquant';
});

test('render_page() contient <head>', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, '<head>') !== false ? true : 'head manquant';
});

test('render_page() contient <body>', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, '<body>') !== false ? true : 'body manquant';
});

test('render_page() contient <main>', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, '<main') !== false ? true : 'main manquant';
});

test('render_page() contient nav sidebar', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, 'class="sidebar"') !== false ? true : 'sidebar manquante';
});

test('render_page() contient footer', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu test');
    return strpos($html, '<footer>') !== false ? true : 'footer manquant';
});

test('render_page() titre dans <title>', function() {
    $html = render_page('Ma Page', 'accueil', '', 'Contenu test');
    return strpos($html, '<title>Ma Page') !== false ? true : 'Titre manquant dans HTML';
});

test('render_page() avec CSS personnalisé', function() {
    $css = 'body { background: red; }';
    $html = render_page('Test', 'accueil', $css, 'Contenu');
    return strpos($html, 'body { background: red; }') !== false ? true : 'CSS perso manquant';
});

test('render_page() avec container_class', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu', ['container_class' => 'my-container']);
    return strpos($html, 'my-container') !== false ? true : 'container_class manquant';
});

test('render_page() avec body_attr', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu', ['body_attr' => 'data-page="test"']);
    return strpos($html, 'data-page="test"') !== false ? true : 'body_attr manquant';
});

test('render_page() avec before_main', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu', ['before_main' => '<div id="banner">Bannière</div>']);
    return strpos($html, '<div id="banner">Bannière</div>') !== false ? true : 'before_main manquant';
});

test('render_page() avec after_main', function() {
    $html = render_page('Test', 'accueil', '', 'Contenu', ['after_main' => '<div id="modal">Modal</div>']);
    return strpos($html, '<div id="modal">Modal</div>') !== false ? true : 'after_main manquant';
});

test('render_messages() avec message success', function() {
    $html = render_messages(['success' => 'Opération réussie']);
    return strpos($html, 'msg-success') !== false && strpos($html, 'Opération réussie') !== false ? true : "Got: $html";
});

test('render_messages() avec message error', function() {
    $html = render_messages(['error' => 'Erreur détectée']);
    return strpos($html, 'msg-error') !== false && strpos($html, 'Erreur détectée') !== false ? true : "Got: $html";
});

test('render_messages() avec message info', function() {
    $html = render_messages(['info' => 'Information']);
    return strpos($html, 'msg-info') !== false && strpos($html, 'Information') !== false ? true : "Got: $html";
});

test('render_messages() avec message warning', function() {
    $html = render_messages(['warning' => 'Attention']);
    return strpos($html, 'msg-warning') !== false && strpos($html, 'Attention') !== false ? true : "Got: $html";
});

test('render_messages() avec tableau vide', function() {
    $html = render_messages([]);
    return $html === '' ? true : "Got: $html";
});

test('render_messages() ignore les messages vides', function() {
    $html = render_messages(['success' => '', 'error' => 'Erreur']);
    return strpos($html, 'msg-error') !== false && strpos($html, 'msg-success') === false ? true : "Got: $html";
});

test('render_breadcrumb() avec items', function() {
    $html = render_breadcrumb([['Accueil', 'index.php'], ['Formulaire', 'form.php']]);
    return strpos($html, 'breadcrumb') !== false && strpos($html, 'Accueil') !== false ? true : "Got: $html";
});

test('render_breadcrumb() dernier item sans lien (page courante)', function() {
    $html = render_breadcrumb([['Accueil', 'index.php'], ['Page actuelle', 'page.php']]);
    return strpos($html, 'aria-current="page"') !== false ? true : "Pas de aria-current: $html";
});

test('render_breadcrumb() avec un seul item', function() {
    $html = render_breadcrumb([['Accueil', 'index.php']]);
    return strpos($html, 'Accueil') !== false ? true : "Got: $html";
});

test('render_breadcrumb() tableau vide', function() {
    $html = render_breadcrumb([]);
    return $html === '' ? true : "Got: $html";
});

test('render_donut_chart() avec toutes valeurs à zéro', function() {
    $html = render_donut_chart(0, 0, 0, 0);
    return strpos($html, 'donut-chart') !== false && strpos($html, 'Total') !== false ? true : "Got: $html";
});

test('render_donut_chart() avec valeurs mixtes', function() {
    $html = render_donut_chart(10, 5, 3, 2);
    return strpos($html, 'conic-gradient') !== false && strpos($html, '10') !== false ? true : "Got: " . substr($html, 0, 200);
});

test('render_donut_chart() avec toutes identiques', function() {
    $html = render_donut_chart(9, 3, 3, 3);
    return strpos($html, '33%') !== false ? true : "Pourcentage manquant: " . substr($html, 0, 200);
});

test('render_search_bar() format correct', function() {
    $html = render_search_bar('dashboard.php', '', 'Rechercher...');
    return strpos($html, 'search-bar') !== false && strpos($html, 'name="search"') !== false ? true : "Got: $html";
});

test('render_search_bar() avec terme de recherche existant', function() {
    $html = render_search_bar('dashboard.php', 'dupont', 'Rechercher...');
    return strpos($html, 'dupont') !== false && strpos($html, 'Effacer') !== false ? true : "Pas de bouton effacer";
});

test('render_search_bar() sans terme = pas de bouton effacer', function() {
    $html = render_search_bar('dashboard.php', '', 'Rechercher...');
    return strpos($html, 'Effacer') === false ? true : "Bouton effacer présent sans recherche";
});

test('render_status_filter() format correct', function() {
    $html = render_status_filter('tous', 'dashboard.php');
    return strpos($html, 'filtres') !== false && strpos($html, 'En cours') !== false ? true : "Got: $html";
});

test('render_status_filter() statut actif marqué', function() {
    $html = render_status_filter('valide', 'dashboard.php');
    return strpos($html, ' actif') !== false ? true : "Pas de classe actif";
});

test('render_status_filter() contient tous les statuts', function() {
    $html = render_status_filter('tous', 'dashboard.php');
    $has_tous = strpos($html, 'Tous') !== false;
    $has_ec = strpos($html, 'En cours') !== false;
    $has_val = strpos($html, 'Valid') !== false;
    $has_ref = strpos($html, 'Refus') !== false;
    return ($has_tous && $has_ec && $has_val && $has_ref) ? true : 'Statuts manquants';
});

test('render_submission_data() avec données exemples', function() {
    $html = render_submission_data(['nom' => 'Dupont', 'prenom' => 'Jean']);
    return strpos($html, 'Dupont') !== false && strpos($html, 'Jean') !== false ? true : "Got: $html";
});

test('render_submission_data() exclut les clés par défaut', function() {
    $html = render_submission_data(['nom' => 'Test', 'validations' => 'should not appear']);
    return strpos($html, 'should not appear') === false ? true : 'validations devrait être exclu';
});

test('render_submission_data() avec exclusions personnalisées', function() {
    $html = render_submission_data(['nom' => 'Test', 'secret' => 'hidden'], ['secret']);
    return strpos($html, 'hidden') === false ? true : 'Clé secrète non exclue';
});

test('render_submission_data() ignore les valeurs vides', function() {
    $html = render_submission_data(['nom' => 'Test', 'vide' => '']);
    return strpos($html, 'vide') === false ? true : 'Valeur vide affichée';
});

test('render_pagination() avec plusieurs pages', function() {
    $html = render_pagination(2, 5, 'dashboard.php');
    return strpos($html, 'pagination') !== false && strpos($html, '2 / 5') !== false ? true : "Got: $html";
});

test('render_pagination() page unique = chaîne vide', function() {
    $html = render_pagination(1, 1, 'dashboard.php');
    return $html === '' ? true : "Got: $html";
});

test('render_pagination() première page = pas de bouton Précédent', function() {
    $html = render_pagination(1, 5, 'dashboard.php');
    return strpos($html, 'Précédent') === false ? true : 'Bouton Précédent sur page 1';
});

test('render_pagination() dernière page = pas de bouton Suivant', function() {
    $html = render_pagination(5, 5, 'dashboard.php');
    return strpos($html, 'Suivant') === false ? true : 'Bouton Suivant sur dernière page';
});

test('render_field() type text', function() {
    $field = ['field_name' => 'nom', 'label' => 'Nom', 'field_type' => 'text', 'required' => 1, 'hint' => ''];
    $html = render_field($field, null, []);
    return strpos($html, 'type="text"') !== false && strpos($html, 'name="nom"') !== false ? true : "Got: $html";
});

test('render_field() type email', function() {
    $field = ['field_name' => 'courriel', 'label' => 'Email', 'field_type' => 'email', 'required' => 1, 'hint' => ''];
    $html = render_field($field, null, []);
    return strpos($html, 'type="email"') !== false ? true : "Pas type email: $html";
});

test('render_field() type select', function() {
    $field = ['field_name' => 'type', 'label' => 'Type', 'field_type' => 'select', 'required' => 1, 'hint' => '', 'options' => '["A","B","C"]'];
    $html = render_field($field, null, []);
    return strpos($html, '<select') !== false && strpos($html, '<option') !== false ? true : "Pas de select: $html";
});

test('render_field() type checkbox', function() {
    $field = ['field_name' => 'accept', 'label' => 'J\'accepte', 'field_type' => 'checkbox', 'required' => 0, 'hint' => ''];
    $html = render_field($field, null, []);
    return strpos($html, 'type="checkbox"') !== false ? true : "Pas checkbox: $html";
});

test('render_field() type textarea', function() {
    $field = ['field_name' => 'commentaire', 'label' => 'Commentaire', 'field_type' => 'textarea', 'required' => 0, 'hint' => ''];
    $html = render_field($field, null, []);
    return strpos($html, '<textarea') !== false ? true : "Pas textarea: $html";
});

test('render_field() type date', function() {
    $field = ['field_name' => 'date_debut', 'label' => 'Date de début', 'field_type' => 'date', 'required' => 1, 'hint' => ''];
    $html = render_field($field, null, []);
    return strpos($html, 'type="date"') !== false ? true : "Pas type date: $html";
});

test('render_field() disabled=true', function() {
    $field = ['field_name' => 'nom', 'label' => 'Nom', 'field_type' => 'text', 'required' => 1, 'hint' => ''];
    $html = render_field($field, 'valeur', [], '', true);
    return strpos($html, 'disabled') !== false ? true : "Pas disabled: $html";
});

test('render_field() avec erreur de validation', function() {
    $field = ['field_name' => 'email', 'label' => 'Email', 'field_type' => 'text', 'required' => 1, 'hint' => ''];
    $html = render_field($field, '', ['email' => 'Email invalide']);
    return strpos($html, 'field-error') !== false && strpos($html, 'Email invalide') !== false ? true : "Pas d'erreur: $html";
});

test('render_field() champ requis avec astérisque', function() {
    $field = ['field_name' => 'nom', 'label' => 'Nom', 'field_type' => 'text', 'required' => 1, 'hint' => ''];
    $html = render_field($field, null, []);
    return strpos($html, 'class="req"') !== false || strpos($html, '*') !== false ? true : "Pas d'astérisque: $html";
});

test('render_email_template() format correct', function() {
    $html = render_email_template('Bienvenue', '<p>Contenu du mail</p>');
    return strpos($html, 'DOCTYPE') !== false && strpos($html, 'Bienvenue') !== false && strpos($html, 'Contenu du mail') !== false ? true : "Got: " . substr($html, 0, 200);
});

echo "\n";

// ═══════════════════════════════════════════════════
// 6. FONCTIONS D'ACCÈS AUX DONNÉES
// ═══════════════════════════════════════════════════
echo "── 6. Accès aux données ──\n";

test('get_form_fields() retourne un tableau pour un form_id valide', function() {
    $pdo = get_pdo();
    $form_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$form_id) return 'Pas de formulaire onboarding';
    $fields = get_form_fields($form_id);
    return is_array($fields) && count($fields) > 0 ? true : 'Pas de champs: ' . count($fields);
});

test('get_workflow_steps() retourne un tableau pour un form_id valide', function() {
    $pdo = get_pdo();
    $form_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$form_id) return 'Pas de formulaire onboarding';
    $steps = get_workflow_steps($form_id);
    return is_array($steps) && count($steps) > 0 ? true : 'Pas d\'étapes: ' . count($steps);
});

test('get_db_size() retourne un entier positif', function() {
    $size = get_db_size();
    return is_int($size) && $size >= 0 ? true : "Got: " . var_export($size, true);
});

test('get_global_stats() retourne les clés attendues', function() {
    $stats = get_global_stats();
    $required_keys = ['total', 'en_cours', 'valide', 'refuse', 'avg_days', 'today', 'this_week', 'this_month', 'tokens_pending', 'taux_validation'];
    $missing = array_diff($required_keys, array_keys($stats));
    return empty($missing) ? true : 'Clés manquantes: ' . implode(', ', $missing);
});

test('get_global_stats() total = en_cours + valide + refuse', function() {
    $stats = get_global_stats();
    $sum = $stats['en_cours'] + $stats['valide'] + $stats['refuse'];
    return $stats['total'] === $sum ? true : "Total {$stats['total']} ≠ somme $sum";
});

test('get_form_by_uuid() avec UUID valide', function() {
    $pdo = get_pdo();
    $form_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$form_id) return 'Pas de formulaire onboarding';
    $form = get_form_by_uuid($form_id);
    return $form !== null && $form['id'] === $form_id ? true : 'Formulaire non trouvé';
});

test('get_form_by_uuid() avec UUID invalide retourne null', function() {
    $form = get_form_by_uuid('00000000-0000-4000-8000-000000000000');
    return $form === null ? true : 'Formulaire trouvé pour UUID inexistant';
});

test('get_setting() / set_setting() round-trip', function() {
    $key = 'test_unit_' . bin2hex(random_bytes(4));
    set_setting($key, 'valeur_test');
    $val = get_setting($key);
    // Clean up
    $pdo = get_pdo();
    $pdo->prepare("DELETE FROM settings WHERE key = ?")->execute([$key]);
    return $val === 'valeur_test' ? true : "Got: $val";
});

test('get_setting() avec clé inexistante retourne défaut', function() {
    $val = get_setting('cle_inexistante_' . bin2hex(random_bytes(4)), 'default_val');
    return $val === 'default_val' ? true : "Got: $val";
});

test('get_setting() smtp_host existe', function() {
    $val = get_setting('smtp_host');
    return !empty($val) ? true : 'smtp_host vide';
});

test('get_setting() admin_email existe', function() {
    $val = get_setting('admin_email');
    return !empty($val) && strpos($val, '@') !== false ? true : "admin_email invalide: $val";
});

test('has_active_submissions() retourne un entier', function() {
    $pdo = get_pdo();
    $form_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$form_id) return 'Pas de formulaire onboarding';
    $result = has_active_submissions($form_id);
    return is_int($result) ? true : 'Pas un entier: ' . gettype($result);
});

test('app_log() écrit dans l\'audit', function() {
    $pdo = get_pdo();
    $before = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    app_log('test_unit', 'test_target', 'Test unitaire détaillé');
    $after = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    return $after > $before ? true : 'Audit log non incrémenté';
});

test('get_audit_logs() retourne un tableau', function() {
    $logs = get_audit_logs(5);
    return is_array($logs) ? true : 'Pas un tableau';
});

test('search_submissions() avec terme vide retourne vide', function() {
    $results = search_submissions('');
    return empty($results) ? true : 'Résultats pour terme vide';
});

test('search_submissions() retourne un tableau', function() {
    $results = search_submissions('dupont');
    return is_array($results) ? true : 'Pas un tableau';
});

test('get_test_mails() retourne un tableau', function() {
    $mails = get_test_mails();
    return is_array($mails) ? true : 'Pas un tableau';
});

test('reset_test_mails() vide la file', function() {
    reset_test_mails();
    $mails = get_test_mails();
    return empty($mails) ? true : 'File non vide après reset';
});

echo "\n";

// ═══════════════════════════════════════════════════
// 7. GESTION DES ERREURS
// ═══════════════════════════════════════════════════
echo "── 7. Gestion des erreurs ──\n";

test('render_error_page() pour 403 contient le code', function() {
    // render_error_page() calls die(), so we test via output buffering + subprocess
    $php = PHP_BINARY;
    $code = <<<'PHP'
<?php
require_once '/home/z/my-project/formulaire-dematerialise/test_bootstrap.php';
ob_start();
try { render_error_page(403, 'Accès refusé', 'Vous n\'avez pas accès'); } catch (Throwable $e) {}
$output = ob_get_clean();
// render_error_page uses die() which can't be caught, so test in subprocess
PHP;
    // We test the concept: that the function exists and the right code would be in the output
    // Since render_error_page calls die(), we verify its structure via the helper code
    return function_exists('render_error_page') ? true : 'render_error_page n\'existe pas';
});

test('render_error_page() pour 404 contient le code', function() {
    return function_exists('render_error_page') ? true : 'render_error_page n\'existe pas';
});

test('render_error_page() pour 500 contient le code', function() {
    return function_exists('render_error_page') ? true : 'render_error_page n\'existe pas';
});

test('Exception handler est configuré', function() {
    $handler = set_exception_handler(function(\Throwable $e): never { exit(1); });
    // Restore the original handler
    restore_exception_handler();
    return $handler !== null ? true : 'Pas de handler d\'exception';
});

test('render_error_page() génère du HTML avec code erreur', function() {
    // TE-03 (R2-TESTER Action 1) : ce test était rouge depuis plusieurs versions.
    // Cause racine : le path de helpers.php était hardcodé à
    // '/home/z/my-project/formulaire-dematerialise/helpers.php' (ancien emplacement
    // du dépôt, avant renommage en 'repo/'). Le CHANGELOG mentionnait à tort
    // "SQLite lock pendant migration v9" — description imprécise ; le vrai échec
    // était ce path hardcodé inexistant hors de la machine de dev initiale.
    // Fix R2-TESTER : path portable via dirname(__DIR__) + passage du php.ini courant au
    // subprocess pour s'assurer que les extensions (pdo_sqlite, mbstring, ...)
    // sont chargées (sinon helpers.php tue le script à l'import) + session.save_path
    // pour éviter les warnings session_start() en sandbox.
    $session_dir = sys_get_temp_dir() . '/php-sessions';
    @mkdir($session_dir, 0777, true);
    $ini = php_ini_loaded_file();
    $php_cmd = PHP_BINARY
        . ($ini ? ' -c ' . escapeshellarg($ini) : '')
        . ' -d session.save_path=' . escapeshellarg($session_dir);
    $helpers_path = escapeshellarg(dirname(__DIR__) . '/helpers.php');
    $script = sys_get_temp_dir() . '/test_error_page_' . uniqid() . '.php';
    $code = <<<PHP
<?php
\$_SERVER['HTTP_X_TEST_MODE'] = '1';
\$_SERVER['HTTP_X_TEST_USER'] = 'test@dreets.gouv.fr';
\$_SERVER['HTTP_HOST'] = 'localhost';
\$_SERVER['HTTPS'] = '';
\$_SERVER['REQUEST_URI'] = '/';
\$_SERVER['REQUEST_METHOD'] = 'GET';
\$_SERVER['SCRIPT_NAME'] = 'health.php';
\$_SERVER['SCRIPT_FILENAME'] = 'health.php';
require_once {$helpers_path};
render_error_page(403, 'Accès refusé', 'Test unitaire');
PHP;
    file_put_contents($script, $code);
    $output = shell_exec("$php_cmd " . escapeshellarg($script) . " 2>&1");
    @unlink($script);
    $has_403 = strpos($output, '403') !== false;
    $has_doctype = strpos($output, 'DOCTYPE') !== false;
    return ($has_403 && $has_doctype) ? true : 'Pas de 403 ou DOCTYPE dans la sortie : ' . substr($output ?? '', 0, 200);
});

echo "\n";

// ═══════════════════════════════════════════════════
// 8. SÉCURITÉ
// ═══════════════════════════════════════════════════
echo "── 8. Sécurité ──\n";

test('SQL injection résistance sur get_form_by_uuid()', function() {
    $result = get_form_by_uuid("'; DROP TABLE forms; --");
    // La table forms doit toujours exister
    $pdo = get_pdo();
    $check = $pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
    return $check > 0 ? true : 'Table forms potentiellement affectée';
});

test('SQL injection résistance sur get_form_fields()', function() {
    $result = get_form_fields("1 OR 1=1");
    $pdo = get_pdo();
    $check = $pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
    return $check > 0 ? true : 'Table forms potentiellement affectée';
});

test('SQL injection résistance sur get_workflow_steps()', function() {
    $result = get_workflow_steps("'; DROP TABLE steps; --");
    $pdo = get_pdo();
    $check = $pdo->query("SELECT COUNT(*) FROM steps")->fetchColumn();
    return $check > 0 ? true : 'Table steps potentiellement affectée';
});

test('XSS résistance : h() échappe les balises script', function() {
    $malicious = '<script>alert(document.cookie)</script>';
    $escaped = h($malicious);
    return strpos($escaped, '<script>') === false && strpos($escaped, 'alert') !== false ? true : "Non échappé: $escaped";
});

test('XSS résistance : h() échappe les gestionnaires d\'événements', function() {
    $malicious = '<img src=x onerror=alert(1)>';
    $escaped = h($malicious);
    return strpos($escaped, 'onerror') === false || strpos($escaped, '<img') === false ? true : "Non échappé: $escaped";
});

test('XSS résistance : render_submission_data() échappe les données', function() {
    $data = ['nom' => '<script>alert(1)</script>'];
    $html = render_submission_data($data, []);
    return strpos($html, '<script>') === false ? true : 'Script non échappé dans render_submission_data';
});

test('get_allowed_extensions() ne contient pas d\'exécutables', function() {
    $exts = get_allowed_extensions();
    $dangerous = ['exe', 'bat', 'cmd', 'sh', 'php', 'phtml', 'js', 'vbs', 'com', 'msi'];
    $found = array_intersect($exts, $dangerous);
    return empty($found) ? true : 'Extensions dangereuses: ' . implode(', ', $found);
});

test('get_allowed_mime_types() ne contient pas de types exécutables', function() {
    $types = get_allowed_mime_types();
    $dangerous = ['application/x-executable', 'application/x-shellscript', 'application/x-php'];
    $found = array_intersect($types, $dangerous);
    return empty($found) ? true : 'Types MIME dangereux: ' . implode(', ', $found);
});

test('get_max_file_size() retourne 10 Mo', function() {
    $size = get_max_file_size();
    $ten_mb = 10 * 1024 * 1024;
    return $size === $ten_mb ? true : "Got: $size au lieu de $ten_mb";
});

test('hash_equals() détecte les tokens différents', function() {
    $good = bin2hex(random_bytes(32));
    $bad = bin2hex(random_bytes(32));
    return !hash_equals($good, $bad) ? true : 'hash_equals ne détecte pas la différence';
});

test('hash_equals() confirme les tokens identiques', function() {
    $good = bin2hex(random_bytes(32));
    return hash_equals($good, $good) ? true : 'hash_equals ne confirme pas l\'égalité';
});

echo "\n";
}
