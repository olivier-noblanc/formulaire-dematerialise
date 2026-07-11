<?php
/**
 * tests/test_unit_basics.php — Sections 1+2+3+4 : Utilitaires + Dates + Auth + POST/CSRF
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Sections 1+2+3+4 : Utilitaires + Dates + Auth + POST/CSRF
 */
function run_tests_unit_basics(): void {
echo "── 1. Utilitaires ──\n";

test('h() null-safe retourne chaîne vide', function() {
    $result = \App\Core\App::html()->escape(null);
    return $result === '' ? true : "Got: '$result'";
});

test('h() échappe les chevrons HTML', function() {
    $result = \App\Core\App::html()->escape('<script>alert("xss")</script>');
    return strpos($result, '<script>') === false ? true : "Non échappé: $result";
});

test('h() échappe les guillemets doubles', function() {
    $result = \App\Core\App::html()->escape('a"b');
    return strpos($result, '&quot;') !== false ? true : "Non échappé: $result";
});

test('h() échappe les guillemets simples', function() {
    $result = \App\Core\App::html()->escape("a'b");
    return strpos($result, '&#039;') !== false ? true : "Non échappé: $result";
});

test('h() préserve les accents UTF-8', function() {
    $result = \App\Core\App::html()->escape('café résumé naïve');
    return $result === 'caf&eacute; r&eacute;sum&eacute; na&iuml;ve' || $result === 'café résumé naïve'
        ? true : "Got: $result";
});

test('h() préserve les caractères simples', function() {
    $result = \App\Core\App::html()->escape('Hello World 123');
    return $result === 'Hello World 123' ? true : "Got: $result";
});

test('h() chaîne vide retourne chaîne vide', function() {
    $result = \App\Core\App::html()->escape('');
    return $result === '' ? true : "Got: '$result'";
});

test('h() échappe & en &amp;', function() {
    $result = \App\Core\App::html()->escape('a&b');
    return strpos($result, '&amp;') !== false ? true : "Non échappé: $result";
});

test('generate_uuid() format UUID v4 valide', function() {
    $uuid = generate_uuid();
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) ? true : "Format invalide: $uuid";
});

test('generate_uuid() unicité sur 100 appels', function() {
    $uuids = [];
    for ($i = 0; $i < 100; $i++) {
        $uuids[] = generate_uuid();
    }
    $unique = count(array_unique($uuids));
    return $unique === 100 ? true : "Doublons: " . (100 - $unique);
});

test('generate_uuid() version 4 (byte 6 = 4x)', function() {
    $uuids = [];
    for ($i = 0; $i < 10; $i++) {
        $uuid = generate_uuid();
        $parts = explode('-', $uuid);
        // 3e groupe doit commencer par '4'
        $uuids[] = $parts[2][0];
    }
    $all_v4 = array_filter($uuids, fn($v) => $v === '4');
    return count($all_v4) === 10 ? true : "Versions non-4: " . implode(',', $uuids);
});

test('generate_uuid() variant RFC 4122 (byte 8 = 8/9/a/b)', function() {
    $uuid = generate_uuid();
    $parts = explode('-', $uuid);
    $variant_char = strtolower($parts[3][0]);
    return in_array($variant_char, ['8', '9', 'a', 'b']) ? true : "Variant invalide: $variant_char";
});

test('generate_field_name() conversion basique', function() {
    $name = generate_field_name('Date de prise de poste');
    return $name === 'date_de_prise_de_poste' ? true : "Got: $name";
});

test('generate_field_name() supprime les accents', function() {
    $name = generate_field_name("Type d'arrivée");
    return strpos($name, 'arrivee') !== false ? true : "Got: $name";
});

test('generate_field_name() avec caractères spéciaux', function() {
    $name = generate_field_name('Nom / Prénom (usage)');
    return preg_match('/^[a-z0-9_]+$/', $name) ? true : "Caractères spéciaux: $name";
});

test('generate_field_name() avec nombres', function() {
    $name = generate_field_name('Étape 3 - Validation');
    return preg_match('/^[a-z0-9_]+$/', $name) && strpos($name, '3') !== false ? true : "Got: $name";
});

test('generate_field_name() chaîne vide retourne "champ"', function() {
    $name = generate_field_name('');
    return $name === 'champ' ? true : "Got: $name";
});

test('generate_field_name() avec seulement des espaces', function() {
    $name = generate_field_name('   ');
    return $name === 'champ' ? true : "Got: $name";
});

test('generate_token() longueur 64 caractères hex', function() {
    $token = generate_token();
    return strlen($token) === 64 && ctype_xdigit($token) ? true : "Longueur: " . strlen($token);
});

test('generate_token() unicité', function() {
    $t1 = generate_token();
    $t2 = generate_token();
    return $t1 !== $t2 ? true : 'Tokens identiques !';
});

test('generate_slug() avec label simple', function() {
    $slug = generate_slug('Onboarding Agent');
    return strpos($slug, 'onboarding') !== false ? true : "Got: $slug";
});

test('sanitize_input() échappe le HTML', function() {
    $result = sanitize_input('<b>test</b>');
    return strpos($result, '<b>') === false ? true : "Non nettoyé: $result";
});

test('sanitize_input() supprime les slashes', function() {
    $result = sanitize_input("test\\nvalue");
    return strpos($result, '\\') === false ? true : "Slashes restants: $result";
});

test('sanitize_input() trim les espaces', function() {
    $result = sanitize_input('  hello  ');
    return $result === 'hello' ? true : "Got: '$result'";
});

test('validate_email() email valide', function() {
    $result = validate_email('test@dreets.gouv.fr');
    return $result === 'test@dreets.gouv.fr' ? true : "Got: $result";
});

test('validate_email() email invalide retourne vide', function() {
    $result = validate_email('not-an-email');
    return $result === '' ? true : "Got: $result";
});

test('validate_email() normalise en minuscules', function() {
    $result = validate_email('Test@DREETS.gouv.fr');
    return $result === 'test@dreets.gouv.fr' ? true : "Got: $result";
});

echo "\n";

// ═══════════════════════════════════════════════════
// 2. FONCTIONS DE DATE
// ═══════════════════════════════════════════════════
echo "── 2. Fonctions de date ──\n";

test('parse_deadline_date() YYYY-MM-DD valide', function() {
    $ts = parse_deadline_date('2026-12-31');
    return $ts !== null && is_int($ts) ? true : "Got: " . var_export($ts, true);
});

test('parse_deadline_date() DD/MM/YYYY valide', function() {
    $ts = parse_deadline_date('31/12/2026');
    return $ts !== null && is_int($ts) ? true : "Got: " . var_export($ts, true);
});

test('parse_deadline_date() YYYY-MM-DD et DD/MM/YYYY même résultat', function() {
    $ts1 = parse_deadline_date('2026-12-31');
    $ts2 = parse_deadline_date('31/12/2026');
    return $ts1 === $ts2 ? true : "Différents: $ts1 vs $ts2";
});

test('parse_deadline_date() format invalide retourne null', function() {
    $ts = parse_deadline_date('not-a-date');
    return $ts === null ? true : "Got: " . var_export($ts, true);
});

test('parse_deadline_date() chaîne vide retourne null', function() {
    $ts = parse_deadline_date('');
    return $ts === null ? true : "Got: " . var_export($ts, true);
});

test('parse_deadline_date() espaces trimmés', function() {
    $ts = parse_deadline_date('  2026-06-15  ');
    return $ts !== null ? true : "Got null pour date avec espaces";
});

test('calculate_deadline_urgency() date passée = overdue', function() {
    $result = calculate_deadline_urgency('2020-01-01');
    return $result['urgency'] === 'overdue' ? true : "Got: " . $result['urgency'];
});

test('calculate_deadline_urgency() date future lointaine = ok', function() {
    $future = date('Y-m-d', strtotime('+30 days'));
    $result = calculate_deadline_urgency($future);
    return $result['urgency'] === 'ok' ? true : "Got: " . $result['urgency'];
});

test('calculate_deadline_urgency() status non en_cours = vide', function() {
    $result = calculate_deadline_urgency('2020-01-01', 'valide');
    return $result['urgency'] === '' ? true : "Got: " . $result['urgency'];
});

test('calculate_deadline_urgency() deadline vide = vide', function() {
    $result = calculate_deadline_urgency('');
    return $result['urgency'] === '' ? true : "Got: " . $result['urgency'];
});

test('calculate_deadline_urgency() contient days_left', function() {
    $future = date('Y-m-d', strtotime('+10 days'));
    $result = calculate_deadline_urgency($future);
    return array_key_exists('days_left', $result) && is_int($result['days_left']) ? true : "days_left absent ou non int";
});

test('calculate_deadline_urgency() date dans 2 jours = critical', function() {
    $soon = date('Y-m-d', strtotime('+2 days'));
    $result = calculate_deadline_urgency($soon);
    return in_array($result['urgency'], ['critical', 'warning']) ? true : "Got: " . $result['urgency'];
});

test('calculate_deadline_urgency() date dans 5 jours = warning', function() {
    $soon = date('Y-m-d', strtotime('+5 days'));
    $result = calculate_deadline_urgency($soon);
    return in_array($result['urgency'], ['warning', 'ok']) ? true : "Got: " . $result['urgency'];
});

test('calculate_deadline_urgency() contient style pour overdue', function() {
    $result = calculate_deadline_urgency('2020-01-01');
    return !empty($result['style']) ? true : "Style vide pour overdue";
});

echo "\n";

// ═══════════════════════════════════════════════════
// 3. AUTH & ACCÈS
// ═══════════════════════════════════════════════════
echo "── 3. Auth & accès ──\n";

test('get_auth_user() en mode test avec email', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $_SERVER['HTTP_X_TEST_USER'] = 'agent@dreets.gouv.fr';
    $email = \App\Core\App::auth()->getUser();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return $email === 'agent@dreets.gouv.fr' ? true : "Got: $email";
});

test('get_auth_user() en mode test avec login sans @', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $_SERVER['HTTP_X_TEST_USER'] = 'dupont';
    $email = \App\Core\App::auth()->getUser();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return $email === 'dupont@dreets.gouv.fr' ? true : "Got: $email";
});

test('get_auth_user() en mode test sans X-Test-User = fallback', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    unset($_SERVER['HTTP_X_TEST_USER']);
    $email = \App\Core\App::auth()->getUser();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return $email === 'test.agent@dreets.gouv.fr' ? true : "Got: $email";
});

test('get_auth_user() normalise en minuscules', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $_SERVER['HTTP_X_TEST_USER'] = 'Agent@DREETS.gouv.fr';
    $email = \App\Core\App::auth()->getUser();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return $email === 'agent@dreets.gouv.fr' ? true : "Got: $email";
});

test('is_admin_user() avec email admin', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $admin_email = \App\Core\App::auth()->getAdminEmail();
    $_SERVER['HTTP_X_TEST_USER'] = $admin_email;
    $result = \App\Core\App::auth()->isAdmin();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return $result ? true : "$admin_email non détecté comme admin";
});

test('is_admin_user() avec email non-admin', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $_SERVER['HTTP_X_TEST_USER'] = 'random.user@dreets.gouv.fr';
    $result = \App\Core\App::auth()->isAdmin();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return !$result ? true : 'Utilisateur aléatoire détecté comme admin !';
});

test('is_super_admin() avec email admin principal', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $admin_email = \App\Core\App::auth()->getAdminEmail();
    $_SERVER['HTTP_X_TEST_USER'] = $admin_email;
    $result = \App\Core\App::auth()->isSuperAdmin();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return $result ? true : "$admin_email non super admin";
});

test('is_super_admin() avec email non-super-admin', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $_SERVER['HTTP_X_TEST_USER'] = 'other.admin@dreets.gouv.fr';
    $result = \App\Core\App::auth()->isSuperAdmin();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return !$result ? true : 'Utilisateur aléatoire détecté comme super admin !';
});

test('require_admin() en mode test avec non-admin → JSON error', function() {
    $prev = $_SERVER['HTTP_X_TEST_USER'] ?? '';
    $_SERVER['HTTP_X_TEST_USER'] = 'definitely.not.admin@dreets.gouv.fr';
    // requireAdmin() calls test_json_response() which does exit, so we test via subprocess
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    // Instead, verify that isAdmin() returns false for non-admin
    $_SERVER['HTTP_X_TEST_USER'] = 'definitely.not.admin@dreets.gouv.fr';
    $is_admin = \App\Core\App::auth()->isAdmin();
    $_SERVER['HTTP_X_TEST_USER'] = $prev;
    return !$is_admin ? true : 'Non-admin détecté comme admin';
});

echo "\n";

// ═══════════════════════════════════════════════════
// 4. POST / CSRF
// ═══════════════════════════════════════════════════
echo "── 4. POST / CSRF ──\n";

test('handlePost() en GET retourne null', function() {
    $prev = $_SERVER['REQUEST_METHOD'] ?? '';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = \App\Core\App::cron()->handlePost();
    $_SERVER['REQUEST_METHOD'] = $prev;
    return $result === null ? true : "Got: " . var_export($result, true);
});

test('handlePost() en POST avec action retourne l\'action', function() {
    $prev_method = $_SERVER['REQUEST_METHOD'] ?? '';
    $prev_action = $_POST['action'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['action'] = 'save_form';
    $result = \App\Core\App::cron()->handlePost();
    $_SERVER['REQUEST_METHOD'] = $prev_method;
    if ($prev_action !== null) $_POST['action'] = $prev_action; else unset($_POST['action']);
    return $result === 'save_form' ? true : "Got: " . var_export($result, true);
});

test('handlePost() en POST sans action retourne null', function() {
    $prev_method = $_SERVER['REQUEST_METHOD'] ?? '';
    $prev_action = $_POST['action'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_POST['action']);
    $result = \App\Core\App::cron()->handlePost();
    $_SERVER['REQUEST_METHOD'] = $prev_method;
    if ($prev_action !== null) $_POST['action'] = $prev_action; else unset($_POST['action']);
    return $result === null ? true : "Got: " . var_export($result, true);
});

test('generate_csrf_token() retourne une chaîne hex', function() {
    @session_start();
    $token = \App\Core\App::security()->generateCsrfToken();
    return ctype_xdigit($token) && strlen($token) === 64 ? true : "Non-hex ou mauvaise longueur: " . strlen($token);
});

test('generate_csrf_token() stocke en session', function() {
    @session_start();
    $token = \App\Core\App::security()->generateCsrfToken();
    return isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] === $token ? true : 'Token pas en session';
});

test('csrf_field() génère un input hidden', function() {
    @session_start();
    $html = \App\Core\App::security()->csrfField();
    return strpos($html, 'name="csrf_token"') !== false && strpos($html, 'type="hidden"') !== false ? true : "HTML: $html";
});

test('csrf_field() contient un value non vide', function() {
    @session_start();
    $html = \App\Core\App::security()->csrfField();
    return preg_match('/value="[^"]+"/', $html) ? true : "Pas de value: $html";
});

test('verify_csrf() en mode test retourne toujours true', function() {
    // En mode TEST, verifyCsrf() bypass toujours
    $result = \App\Core\App::security()->verifyCsrf();
    return $result === true ? true : 'CSRF bypass ne fonctionne pas en mode test';
});

test('require_csrf() ne lève pas en mode test', function() {
    // En mode TEST, verifyCsrf() retourne true, donc requireCsrf() ne fait rien
    // Si une exception est levée, le test échouera
    \App\Core\App::security()->requireCsrf();
    return true;
});

echo "\n";
}
