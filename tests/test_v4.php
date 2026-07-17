<?php
/**
 * test_v4.php — Tests automatisés des fonctionnalités v4.0.0
 *
 * Teste TOUTES les nouveautés de la version 4.0.0 via requêtes HTTP réelles
 * vers le serveur PHP built-in, en utilisant le mécanisme X-Test-Mode.
 *
 * Fonctionnalités testées (voir tests/test_v4_*.php pour les implémentations) :
 *   - Phase 1  : Infrastructure (schema_version, BLOB, rgpd_consent, rate_limits)
 *   - Phase 2  : Conformité RGPD (mentions légales, export, purge)
 *   - Phase 3  : Health check (endpoint monitoring)
 *   - Phase 4  : Statistiques (par période)
 *   - Phase 5  : Webhooks (configuration + test)
 *   - Phase 6  : Pièces jointes BLOB (upload + download)
 *   - Phase 7  : Recherche plein texte
 *   - Phase 8  : Historique des relances
 *   - Phase 9  : Nouveaux formulaires seedés (6 formulaires)
 *   - Phase 10 : Consentement RGPD à la soumission
 *   - Phase 11 : Rate limiting
 *   - Phase 12 : Documentation avec captures d'écran
 *
 * Usage: php test_v4.php
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/test_v4_helpers.php';
require_once __DIR__ . '/test_v4_infrastructure.php';
require_once __DIR__ . '/test_v4_rgpd_stats.php';
require_once __DIR__ . '/test_v4_webhooks_attachments.php';
require_once __DIR__ . '/test_v4_search_relance_forms.php';
require_once __DIR__ . '/test_v4_compliance.php';

// ── CONFIG ─────────────────────────────────────────────────────
$BASE   = __DIR__;
$PHP    = 'php';
$PORT   = 8766;  // Port différent de test_http.php pour éviter les conflits
$SERVER = "http://localhost:$PORT";

// Extensions requises — fallback si absentes du php.ini par défaut
$REQUIRED_EXT = ['mbstring', 'pdo_sqlite', 'sqlite3', 'json', 'openssl', 'curl', 'fileinfo', 'session'];
$PHP_EXT_FLAGS = '';
foreach ($REQUIRED_EXT as $ext) {
    if (!extension_loaded($ext)) {
        $PHP_EXT_FLAGS .= " -d extension=$ext";
    }
}

// ── CLEANUP & SERVER START ─────────────────────────────────────
echo bold("Préparation de l'environnement de test v4.0.0...\n");
kill_port($PORT);
sleep(1);
$cookie_pattern = test_temp_dir() . '/wf_v4_test_cookies_*.txt';
if (PHP_OS_FAMILY === 'Windows') {
    shell_exec("del /Q " . escapeshellarg($cookie_pattern) . " 2>NUL");
} else {
    shell_exec("rm -f " . escapeshellarg($cookie_pattern));
}
shell_exec("rm -f $BASE/db/workflow_test.db");

// Démarrer le serveur
shell_exec("cd $BASE && $PHP $PHP_EXT_FLAGS -S localhost:$PORT -t . > " . escapeshellarg(test_temp_dir() . '/php_server_v4.log') . " 2>&1 &");
sleep(2);

// Vérifier que le serveur répond
$check = @file_get_contents("$SERVER/test_api.php?action=stats", false, stream_context_create([
    'http' => ['method' => 'GET', 'header' => "X-Test-Mode: 1\r\nX-Test-User: test.agent\r\n", 'timeout' => 3]
]));
if ($check === false) {
    echo red("ERREUR: Le serveur PHP n'a pas pu démarrer sur le port $PORT\n");
    exit(1);
}

echo bold("\n═══════════════════════════════════════════════════════════════════\n");
echo bold("  TESTS V4.0.0 — Formulaires Dématérialisés DREETS\n");
echo bold("  Mode X-Test-Mode (Serveur PHP réel sur port $PORT)\n");
echo bold("═══════════════════════════════════════════════════════════════════\n\n");

// ── SETUP: Ajouter l'admin test ────────────────────────────────
$r = api('add_admin', ['email' => 'test.agent@exemple.invalid']);
$admin_ok = ($r['json']['ok'] ?? false) === true;

// Ajouter aussi un admin pour les tests RGPD non-admin
$r = api('add_admin', ['email' => 'nonadmin@exemple.invalid']);
// Ce sera retiré plus tard pour tester l'accès

// ════════════════════════════════════════════════════════════════
// EXÉCUTION DES PHASES (modules thématiques)
// ════════════════════════════════════════════════════════════════
run_tests_v4_infrastructure();         // Phase 1 : Core Infrastructure
run_tests_v4_rgpd();                   // Phase 2 : RGPD Compliance
run_tests_v4_health();                 // Phase 3 : Health Check
run_tests_v4_stats();                  // Phase 4 : Statistiques
run_tests_v4_attachments();            // Phase 6 : Pièces jointes BLOB
run_tests_v4_search();                 // Phase 7 : Recherche plein texte
run_tests_v4_relance();                // Phase 8 : Historique des relances
run_tests_v4_new_forms();              // Phase 9 : Nouveaux formulaires seedés
run_tests_v4_consent();                // Phase 10 : Consentement RGPD
run_tests_v4_rate_limit();             // Phase 11 : Rate Limiting
run_tests_v4_documentation();          // Phase 12 : Documentation

// ════════════════════════════════════════════════════════════════
// RÉSUMÉ
// ════════════════════════════════════════════════════════════════
$exit_code = print_test_summary('RÉSUMÉ V4.0.0');

// ── CLEANUP ────────────────────────────────────────────────────
kill_port($PORT);
$cleanup_cookie = test_temp_dir() . '/wf_v4_test_cookies_*.txt';
if (PHP_OS_FAMILY === 'Windows') {
    shell_exec("del /Q " . escapeshellarg($cleanup_cookie) . " 2>NUL");
} else {
    shell_exec("rm -f " . escapeshellarg($cleanup_cookie));
}

if ($exit_code !== 0) {
    echo yellow("\nDB test conservée pour inspection : $BASE/db/workflow_test.db\n");
    echo yellow("Logs serveur : " . test_temp_dir() . "/php_server_v4.log\n");
} else {
    shell_exec("rm -f $BASE/db/workflow_test.db");
    echo green("\nDB test nettoyée.\n");
}

echo "\n";
exit($exit_code);
