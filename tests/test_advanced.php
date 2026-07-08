<?php
/**
 * test_advanced.php — Tests avancés — CircuitDémat
 * Couvre workflow engine, RGPD, admin, fichiers, edge cases, email, stats.
 *
 * Les tests sont répartis en modules thématiques sous tests/test_advanced_*.php :
 *   - test_advanced_workflow.php         : Section 1 — Workflow Engine
 *   - test_advanced_forms_files.php      : Sections 2 + 5 — Form Builder + File Handling
 *   - test_advanced_rgpd.php             : Section 3 — RGPD & Data Protection
 *   - test_advanced_admin.php            : Section 4 — Admin Management
 *   - test_advanced_edge_email_stats.php : Sections 6 + 7 + 8 — Edge cases + Email + Stats
 *
 * Usage: php test_advanced.php
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/test_advanced_workflow.php';
require_once __DIR__ . '/test_advanced_forms_files.php';
require_once __DIR__ . '/test_advanced_rgpd.php';
require_once __DIR__ . '/test_advanced_admin.php';
require_once __DIR__ . '/test_advanced_edge_email_stats.php';
require_once __DIR__ . '/test_advanced_conditional_workflow.php';
// admin_forms_json.php n'est pas chargé par helpers.php — requis pour les
// tests validate_form_json() de la section 9 (branches conditionnelles).
require_once __DIR__ . '/lib/admin_forms_json.php';

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  Tests avancés — CircuitDémat v5.19.0          ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ═══════════════════════════════════════════════════
// Helper: get onboarding form ID for DB tests
// ═══════════════════════════════════════════════════
$pdo = \App\Core\App::db()->getPdo();
$onboarding_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();

// ═══════════════════════════════════════════════════
// EXÉCUTION DES SECTIONS (modules thématiques)
// ═══════════════════════════════════════════════════
run_tests_advanced_workflow();      // Section 1 : Workflow Engine
run_tests_advanced_forms();         // Section 2 : Form Builder Integration
run_tests_advanced_rgpd();          // Section 3 : RGPD & Data Protection
run_tests_advanced_admin();         // Section 4 : Admin Management
run_tests_advanced_files();         // Section 5 : File Handling
run_tests_advanced_edge();          // Section 6 : Edge Cases & Stress
run_tests_advanced_email();         // Section 7 : Email & Notifications
run_tests_advanced_stats();         // Section 8 : Stats & Monitoring
run_tests_advanced_conditional_workflow(); // Section 9 : Branches conditionnelles (v19)

exit(print_test_summary('RÉSULTATS AVANCÉS'));
