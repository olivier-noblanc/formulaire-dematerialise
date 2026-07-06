<?php
/**
 * test_unit.php — Tests unitaires avancés — CircuitDémat
 * Couvre toutes les fonctions helper ajoutées lors de l'audit remediation.
 *
 * Les tests sont répartis en modules thématiques sous tests/test_unit_*.php :
 *   - test_unit_helpers.php          : Fonctions utilitaires partagées (_find_function_in_libs,
 *                                      _extract_function_body, _run_http_subprocess)
 *   - test_unit_basics.php           : Sections 1-4 — Utilitaires, Dates, Auth, POST/CSRF
 *   - test_unit_render_data.php      : Sections 5-8 — Rendu, Accès données, Erreurs, Sécurité
 *   - test_unit_nav_utils.php        : Sections 9-11 — Navigation, Version, Utilitaires suppl.
 *   - test_unit_wave4_validation.php : Section 12.1-12.9 — validate_input() (9 règles)
 *   - test_unit_wave4_security.php   : Section 12.10-12.14 — encrypt, parse_date, security_log,
 *                                      security_headers, rate limiting
 *   - test_unit_wave5.php            : Section 13 — Wave 5 (Alertes, release_pdo, régression SQL)
 *   - test_unit_wave6.php            : Section 14 — Wave 6 (Brouillons, indicateur progression)
 *   - test_unit_wave7.php            : Section 15 — Wave 7 (submission_view.php E2E)
 *   - test_unit_wave8_9.php          : Sections 16-17 — Wave 8 (v5.25.3 bugs) + Wave 9 (t_jargon, runtime HTTP)
 *
 * Usage: php test_unit.php
 */

// ── MBSRTING POLYFILL (S-14 / A-18) ──────────────────────────────
// Permet l'exécution des tests sans l'extension mbstring chargée.
// Les valeurs de test sont en ASCII pur, donc strtolower/substr suffisent.
// Doit être défini AVANT require_once test_bootstrap.php (qui charge helpers.php).
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $e = 'UTF-8') { return strtolower($s); }
    function mb_strtoupper($s, $e = 'UTF-8') { return strtoupper($s); }
    function mb_strlen($s, $e = 'UTF-8') { return strlen($s); }
    function mb_substr($s, $o, $l = null, $e = 'UTF-8') { return $l !== null ? substr($s, $o, $l) : substr($s, $o); }
    function mb_strpos($h, $n, $o = 0, $e = 'UTF-8') { return strpos($h, $n, $o); }
    function mb_check_encoding($s = null, $e = 'UTF-8') { return true; }
    function mb_substr_count($h, $n, $e = 'UTF-8') { return substr_count($h, $n); }
    function mb_split($p, $s, $l = -1) { return preg_split('/' . $p . '/', $s, $l); }
    function mb_http_input($t = '') { return 'UTF-8'; }
    function mb_internal_encoding($e = null) { return 'UTF-8'; }
    function mb_encode_mimeheader($s, $c = 'UTF-8', $t = 'B', $lf = "\r\n", $ind = 0) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }
    function mb_decode_mimeheader($s) { return $s; }
    function mb_send_mail($to, $subj, $body, $hdrs = null) { return mail($to, $subj, $body, $hdrs); }
}

// ── BYPASS DU CHECK D'EXTENSION MBSRTING (S-14 / A-18) ───────────
// helpers.php lignes 100-112 tue le script si mbstring n'est pas chargée,
// SAUF si SCRIPT_NAME = 'health.php'. On utilise cette exception pour
// pouvoir charger helpers.php en environnement de test sans mbstring.
if (!isset($_SERVER['SCRIPT_NAME']) || $_SERVER['SCRIPT_NAME'] !== 'health.php') {
    $_SERVER['SCRIPT_NAME']     = 'health.php';
    $_SERVER['SCRIPT_FILENAME'] = 'health.php';
}

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/test_unit_helpers.php';
require_once __DIR__ . '/test_unit_basics.php';
require_once __DIR__ . '/test_unit_render_data.php';
require_once __DIR__ . '/test_unit_nav_utils.php';
require_once __DIR__ . '/test_unit_wave4_validation.php';
require_once __DIR__ . '/test_unit_wave4_security.php';
require_once __DIR__ . '/test_unit_wave5.php';
require_once __DIR__ . '/test_unit_wave6.php';
require_once __DIR__ . '/test_unit_wave7.php';
require_once __DIR__ . '/test_unit_wave8_9.php';

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  Tests unitaires avancés — CircuitDémat        ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ═══════════════════════════════════════════════════
// EXÉCUTION DES SECTIONS (modules thématiques)
// ═══════════════════════════════════════════════════
run_tests_unit_basics();              // Sections 1-4 : Utilitaires, Dates, Auth, POST/CSRF
run_tests_unit_render_data();         // Sections 5-8 : Rendu, Accès données, Erreurs, Sécurité
run_tests_unit_nav_utils();           // Sections 9-11 : Navigation, Version, Utilitaires suppl.
run_tests_unit_wave4_validation();    // Section 12.1-12.9 : validate_input() (9 règles)
run_tests_unit_wave4_security();      // Section 12.10-12.14 : encrypt, parse_date, security_log, etc.
run_tests_unit_wave5();               // Section 13 : Wave 5 — R2-TESTER
run_tests_unit_wave6();               // Section 14 : Wave 6 — S2-CTO
run_tests_unit_wave7();               // Section 15 : Wave 7 — S3-TESTER
run_tests_unit_wave8_9();             // Sections 16-17 : Wave 8 + Wave 9

// ═══════════════════════════════════════════════════
// RÉSULTATS
// ═══════════════════════════════════════════════════
exit(print_test_summary('RÉSULTATS UNITAIRES'));
