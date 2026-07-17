<?php
/**
 * test_refactor.php — Tests ciblés pour les 5 actions de refactoring v5.14.0
 * Stub mbstring + bypass du check d'extensions pour tourner sans l'extension
 */

// Stub mbstring AVANT tout chargement
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $e='UTF-8') { return strtolower($s); }
    function mb_strtoupper($s, $e='UTF-8') { return strtoupper($s); }
    function mb_strlen($s, $e='UTF-8') { return strlen($s); }
    function mb_substr($s,$o,$l=null,$e='UTF-8') { return $l!==null?substr($s,$o,$l):substr($s,$o); }
    function mb_strpos($h,$n,$o=0,$e='UTF-8') { return strpos($h,$n,$o); }
    function mb_check_encoding($s=null,$e='UTF-8') { return true; }
    function mb_substr_count($h,$n,$e='UTF-8') { return substr_count($h,$n); }
    function mb_split($p,$s,$l=-1) { return preg_split('/'.$p.'/',$s,$l); }
    function mb_http_input($t='') { return 'UTF-8'; }
    function mb_internal_encoding($e=null) { return 'UTF-8'; }
    function mb_encode_mimeheader($s,$c='UTF-8',$t='B',$lf="\r\n",$ind=0) { return '=?UTF-8?B?'.base64_encode($s).'?='; }
    function mb_decode_mimeheader($s) { return $s; }
    function mb_send_mail($to,$subj,$body,$hdrs=null) { return mail($to,$subj,$body,$hdrs); }
}

// Patch: faire croire que mbstring est chargée
// On surcharge la vérification dans helpers.php en modifiant le tableau AVANT le require
$_SERVER['HTTP_X_TEST_MODE'] = '1';
$_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
$_SERVER['AUTH_USER'] = 'DREETS\testeur';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = '';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = 'health.php';  // Bypass le check mbstring (health.php est exempté)
$_SERVER['SCRIPT_FILENAME'] = 'health.php';

ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . '/config.php';
@session_start();

// Charger helpers.php (le check mbstring est bypassé car SCRIPT_NAME = health.php)
require_once __DIR__ . '/helpers.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ── FRAMEWORK DE TEST ──
$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $msg = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name — $msg\n";
        $failed++;
    }
}

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  Tests Refactoring v5.14.0                      ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ════════════════════════════════════════════════════════
// Action 1: require_admin()
// ════════════════════════════════════════════════════════
echo "── Action 1 : require_admin() ──\n";

assert_test('require_admin() existe', function_exists('require_admin'),
    'Fonction non trouvée dans helpers.php');

// Vérifier que require_admin() appelle is_admin_user() et is_super_admin()
$ref = new ReflectionFunction('require_admin');
$code = file_get_contents(__DIR__ . '/helpers.php');
assert_test('require_admin() vérifie admin ET super-admin',
    strpos($code, 'function require_admin') !== false &&
    strpos($code, '!is_admin_user() && !is_super_admin()') !== false,
    'La vérification admin+super_admin est absente');

// Vérifier que les 8 fichiers utilisent require_admin()
$admin_files = ['index.php?p=admin_alerts', 'index.php?p=admin_forms', 'index.php?p=admin_settings', 'backup.php',
                'form_preview.php', 'monitoring.php', 'rgpd.php', 'stats.php'];
foreach ($admin_files as $f) {
    $content = file_get_contents(__DIR__ . '/' . $f);
    $has_require_admin = strpos($content, 'require_admin()') !== false;
    $no_inline_check = (strpos($content, "!is_admin_user() && !is_super_admin()") === false) ||
                       (strpos($content, "function require_admin") !== false); // helpers.php itself
    assert_test("$f utilise require_admin()", $has_require_admin, 'Appel manquant');
    assert_test("$f n'a plus de bloc admin inline", $no_inline_check, 'Bloc inline encore présent');
}

echo "\n";

// ════════════════════════════════════════════════════════
// Action 2: calculate_deadline_urgency()
// ════════════════════════════════════════════════════════
echo "── Action 2 : calculate_deadline_urgency() ──\n";

assert_test('calculate_deadline_urgency() existe', function_exists('calculate_deadline_urgency'),
    'Fonction non trouvée');
assert_test('parse_deadline_date() existe', function_exists('parse_deadline_date'),
    'Fonction non trouvée');

// Test parsing YYYY-MM-DD
$ts = parse_deadline_date('2026-12-31');
assert_test('parse_deadline_date YYYY-MM-DD retourne timestamp', $ts !== null && $ts > 0,
    'Timestamp: ' . var_export($ts, true));

// Test parsing DD/MM/YYYY
$ts = parse_deadline_date('31/12/2026');
assert_test('parse_deadline_date DD/MM/YYYY retourne timestamp', $ts !== null && $ts > 0,
    'Timestamp: ' . var_export($ts, true));

// Test parsing invalide
$ts = parse_deadline_date('invalid-date');
assert_test('parse_deadline_date invalide retourne null', $ts === null,
    'Devrait être null: ' . var_export($ts, true));

// Test urgency : date future lointaine → ok
$dl = calculate_deadline_urgency('2099-12-31', 'en_cours');
assert_test('deadline future lointaine → urgency=ok', $dl['urgency'] === 'ok',
    'Urgency: ' . $dl['urgency']);

// Test urgency : date passée → overdue
$dl = calculate_deadline_urgency('2020-01-01', 'en_cours');
assert_test('deadline passée → urgency=overdue', $dl['urgency'] === 'overdue',
    'Urgency: ' . $dl['urgency']);
assert_test('deadline passée → days_left < 0', $dl['days_left'] < 0,
    'days_left: ' . $dl['days_left']);

// Test urgency : status non en_cours → pas d'urgence
$dl = calculate_deadline_urgency('2020-01-01', 'valide');
assert_test('deadline status=valide → urgency vide', $dl['urgency'] === '',
    'Urgency: ' . $dl['urgency']);

// Test urgency : deadline vide
$dl = calculate_deadline_urgency('', 'en_cours');
assert_test('deadline vide → urgency vide', $dl['urgency'] === '',
    'Urgency: ' . $dl['urgency']);

// Test style overdue
$dl = calculate_deadline_urgency('2020-01-01', 'en_cours');
assert_test('deadline overdue → style contient color', strpos($dl['style'], 'color:') !== false,
    'Style: ' . $dl['style']);

// Vérifier que les 3 fichiers utilisent calculate_deadline_urgency()
// (monitoring.php utilise parse_deadline_date() car seuil différent de 10j)
$deadline_func_files = ['dashboard.php', 'my_submissions.php', 'submission_view.php'];
foreach ($deadline_func_files as $f) {
    $content = file_get_contents(__DIR__ . '/' . $f);
    $uses_func = strpos($content, 'calculate_deadline_urgency') !== false;
    assert_test("$f utilise calculate_deadline_urgency()", $uses_func, 'Appel manquant');
}

// monitoring.php : utilise parse_deadline_date() (seuil 10j ≠ 2/5j de calculate_deadline_urgency)
$m_content = file_get_contents(__DIR__ . '/monitoring.php');
assert_test('monitoring.php utilise parse_deadline_date()', strpos($m_content, 'parse_deadline_date') !== false,
    'Appel manquant');
// Vérifier que monitoring.php n'a plus le regex inline de parsing
assert_test('monitoring.php n\'a plus le regex YYYY-MM-DD inline',
    strpos($m_content, "preg_match('/^\\d{4}-\\d{2}-\\d{2}") === false,
    'Regex encore présent');

echo "\n";

// ════════════════════════════════════════════════════════
// Action 3: get_global_stats()
// ════════════════════════════════════════════════════════
echo "── Action 3 : StatsService::getGlobalStats() ──\n";

assert_test('StatsService est disponible', \App\Core\App::getInstance()->has(\App\Stats\StatsService::class),
    'StatsService non enregistrée');

$gstats = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
$expected_keys = ['total', 'en_cours', 'valide', 'refuse', 'taux_validation', 'avg_days',
                  'today', 'this_week', 'this_month', 'tokens_pending', 'attachments_count'];
$missing = array_diff($expected_keys, array_keys($gstats));
assert_test('StatsService::getGlobalStats() clés complètes', empty($missing),
    'Clés manquantes: ' . implode(', ', $missing));

assert_test('StatsService::getGlobalStats() total >= 0', $gstats['total'] >= 0,
    'Total: ' . $gstats['total']);

assert_test('StatsService::getGlobalStats() taux_validation est numérique', is_numeric($gstats['taux_validation']),
    'taux_validation: ' . var_export($gstats['taux_validation'], true));

// Vérifier que les 3 fichiers utilisent StatsService::getGlobalStats()
$stats_files = ['dashboard.php', 'index.php', 'monitoring.php'];
foreach ($stats_files as $f) {
    $content = file_get_contents(__DIR__ . '/' . $f);
    $uses_func = strpos($content, 'StatsService::class)->getGlobalStats()') !== false;
    assert_test("$f utilise StatsService::getGlobalStats()", $uses_func, 'Appel manquant');
}

echo "\n";

// ════════════════════════════════════════════════════════
// Action 4: get_pdo() un seul appel dans admin_forms.php
// ════════════════════════════════════════════════════════
echo "── Action 4 : get_pdo() unique dans admin_forms.php ──\n";

$af_content = file_get_contents(__DIR__ . '/admin_forms.php');
$pdo_count = substr_count($af_content, 'get_pdo()');
assert_test('admin_forms.php : get_pdo() appelé 1 seule fois', $pdo_count === 1,
    "Appels trouvés: $pdo_count (attendu: 1)");

// Vérifier qu'il y a un $pdo = get_pdo() en haut
assert_test('admin_forms.php : $pdo = get_pdo() en tête',
    strpos($af_content, '$pdo = get_pdo();') !== false,
    'Assignation $pdo introuvable');

echo "\n";

// ════════════════════════════════════════════════════════
// Action 5: render_footer() dans health.php + harmonisation
// ════════════════════════════════════════════════════════
echo "── Action 5 : render_footer() + harmonisation ──\n";

$health_content = file_get_contents(__DIR__ . '/health.php');
assert_test('health.php appelle render_footer()', strpos($health_content, 'render_footer()') !== false,
    'Appel manquant');

// Vérifier harmonisation admin check (rgpd.php et stats.php utilisent require_admin())
$rgpd_content = file_get_contents(__DIR__ . '/rgpd.php');
$stats_content = file_get_contents(__DIR__ . '/stats.php');
assert_test('rgpd.php utilise require_admin()', strpos($rgpd_content, 'require_admin()') !== false,
    'Appel manquant');
assert_test('stats.php utilise require_admin()', strpos($stats_content, 'require_admin()') !== false,
    'Appel manquant');

echo "\n";

// ════════════════════════════════════════════════════════
// SYNTAX CHECK : tous les fichiers modifiés
// ════════════════════════════════════════════════════════
echo "── Vérification syntaxique ──\n";

$modified_files = [
    'helpers.php', 'index.php?p=admin_alerts', 'index.php?p=admin_forms', 'index.php?p=admin_settings',
    'backup.php', 'dashboard.php', 'form_preview.php', 'health.php',
    'index.php', 'monitoring.php', 'my_submissions.php', 'rgpd.php',
    'stats.php', 'submission_view.php'
];

$php_bin = 'php';
foreach ($modified_files as $f) {
    $output = [];
    exec("$php_bin -l " . escapeshellarg(__DIR__ . '/' . $f) . " 2>&1", $output, $exit_code);
    $msg = implode("\n", $output);
    assert_test("Syntaxe $f OK", $exit_code === 0, trim($msg));
}

echo "\n══════════════════════════════════════════════════\n";
echo "  Résultat : $passed réussi(s), $failed échoué(s)\n";
echo "══════════════════════════════════════════════════\n";

/** @phpstan-ignore-next-line greater.alwaysFalse */
exit($failed > 0 ? 1 : 0);
