<?php
/**
 * test_e2e.php — Tests End-to-End intensifs du CircuitDémat v4.3.0
 *
 * Simule des soumissions réelles de formulaires via la base de données,
 * teste le workflow complet de bout en bout, les cas limites,
 * la sécurité, les uploads, la délégation, l'annulation, etc.
 *
 * Les tests sont répartis en modules thématiques sous tests/test_e2e_*.php :
 *   - test_e2e_workflow.php          : Sections 2 + 3 + 4 — Soumission + Workflow + Workflow complet
 *   - test_e2e_lifecycle.php         : Sections 5 + 6 + 7 — Refus + Annulation + Délégation
 *   - test_e2e_security_files.php    : Sections 8 + 9 + 10 — Sécurité + Upload + Outboarding
 *   - test_e2e_utils_integrity.php   : Sections 11 + 12 + 13 — Utils + Intégrité + RGPD
 *   - test_e2e_tokens_fields.php     : Sections 14 + 15 — Relance + Types de champs
 *   - test_e2e_validator.php         : Sections 16 + 17 — Champs validateur + Cycle complet
 *
 * ⚠️  SÉCURITÉ : Ce script force le mode TEST pour intercepter tous les
 *     envois d'emails. AUCUN email réel n'est envoyé pendant les tests.
 *     Les adresses utilisent le domaine @e2e.test (inexistant).
 *
 * Usage: php test_e2e.php
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/test_e2e_workflow.php';
require_once __DIR__ . '/test_e2e_lifecycle.php';
require_once __DIR__ . '/test_e2e_security_files.php';
require_once __DIR__ . '/test_e2e_utils_integrity.php';
require_once __DIR__ . '/test_e2e_tokens_fields.php';
require_once __DIR__ . '/test_e2e_validator.php';

// Override test user for E2E suite
$_SERVER['HTTP_X_TEST_USER'] = 'testeur_e2e@e2e.test';

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  Tests E2E intensifs — CircuitDémat v4.3.0            ║\n";
echo "║  Soumissions réelles, workflow complet, cas limites     ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$pdo = get_pdo();

// ═══════════════════════════════════════════════════════════════
// 1. PRÉPARATION — Récupérer les formulaires et étapes
// ═══════════════════════════════════════════════════════════════
echo "── 1. Préparation de l'environnement de test ──\n";

$onboarding_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
$outboarding_id = $pdo->query("SELECT id FROM forms WHERE slug='outboarding' LIMIT 1")->fetchColumn();

test('Formulaire onboarding trouvé', function() use ($onboarding_id) {
    return $onboarding_id ? true : 'Formulaire onboarding introuvable';
});

test('Formulaire outboarding trouvé', function() use ($outboarding_id) {
    return $outboarding_id ? true : 'Formulaire outboarding introuvable';
});

// Récupérer les étapes de l'onboarding
$steps_onboarding = [];
$stmt = $pdo->prepare("SELECT id, label, ordre FROM steps WHERE form_id = ? ORDER BY ordre");
$stmt->execute([$onboarding_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $steps_onboarding[] = $row;
}

test('Onboarding a au moins 2 étapes', function() use ($steps_onboarding) {
    return count($steps_onboarding) >= 2 ? true : 'Seulement ' . count($steps_onboarding) . ' étapes';
});

// S'assurer que les étapes ont des destinataires
foreach ($steps_onboarding as $step) {
    $rcpt_count = $pdo->prepare("SELECT COUNT(*) FROM step_recipients WHERE step_id = ?");
    $rcpt_count->execute([$step['id']]);
    $count = $rcpt_count->fetchColumn();
    if ($count == 0) {
        $email = strtolower(preg_replace('/[^a-z0-9]/', '', $step['label'])) . '@e2e.test';
        $stmt_ins = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_ins->execute([generate_uuid(), $step['id'], $email]);
    }
}

// Récupérer les champs du formulaire onboarding
$fields_onboarding = [];
$stmt_fields = $pdo->prepare("SELECT id, label, field_type, field_name, required FROM form_fields WHERE form_id = ? ORDER BY ordre");
$stmt_fields->execute([$onboarding_id]);
while ($row = $stmt_fields->fetch(PDO::FETCH_ASSOC)) {
    $fields_onboarding[] = $row;
}

test('Onboarding a des champs de formulaire', function() use ($fields_onboarding) {
    return count($fields_onboarding) >= 1 ? true : 'Aucun champ trouvé';
});

echo "\n";

// Variables partagées entre sections (remplies par les modules suivants)
$submission_uuid = null;
$agent_email = null;
$data_json = null;
$first_token = null;
$full_workflow_uuid = null;

// ═══════════════════════════════════════════════════════════════
// EXÉCUTION DES SECTIONS (modules thématiques)
// ═══════════════════════════════════════════════════════════════
run_tests_e2e_submission();        // Section 2 : Soumission complète onboarding
run_tests_e2e_workflow_step();     // Section 3 : Workflow étape par étape
run_tests_e2e_workflow_full();     // Section 4 : Workflow complet
run_tests_e2e_refusal();           // Section 5 : Refus de demande
run_tests_e2e_cancel();            // Section 6 : Annulation de demande
run_tests_e2e_delegation();        // Section 7 : Délégation de validation
run_tests_e2e_security();          // Section 8 : Cas limites et sécurité
run_tests_e2e_files();             // Section 9 : Upload de fichiers
run_tests_e2e_outboarding();       // Section 10 : Formulaire outboarding
run_tests_e2e_utils();             // Section 11 : Fonctions utilitaires
run_tests_e2e_integrity();         // Section 12 : Intégrité des données
run_tests_e2e_rgpd();              // Section 13 : RGPD et conformité
run_tests_e2e_tokens();            // Section 14 : Relance et expiration
run_tests_e2e_fields();            // Section 15 : Types de champs
run_tests_e2e_validator_fields();  // Section 16 : Champs validateur
run_tests_e2e_validator_cycle();   // Section 17 : Cycle complet validate.php

// ═══════════════════════════════════════════════════════════════
// RÉSULTATS
// ═══════════════════════════════════════════════════════════════
$exit_code = print_test_summary('RÉSULTATS E2E');

echo "\n📊 Résumé des données de test créées :\n";
$total_submissions = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
$total_tokens = $pdo->query("SELECT COUNT(*) FROM tokens")->fetchColumn();
$total_attachments = $pdo->query("SELECT COUNT(*) FROM attachments")->fetchColumn();
$total_delegations = $pdo->query("SELECT COUNT(*) FROM delegations")->fetchColumn();
echo "  • Soumissions : $total_submissions\n";
echo "  • Tokens : $total_tokens\n";
echo "  • Pièces jointes : $total_attachments\n";
echo "  • Délégations : $total_delegations\n";

exit($exit_code);
