<?php
/**
 * Test standalone pour le cycle filled_by (Option A).
 * N'utilise que PDO, pas helpers.php, pas de session.
 * Usage: php test_filled_by.php
 */

// Bug #15 (P3-A) : path hardcodé → relatif à __DIR__ pour fonctionner
// depuis n'importe quel environnement (sandbox, CI, prod, dev local).
$db = dirname(__DIR__) . '/db/workflow_test.db';

if (!file_exists($db)) {
    echo "ERREUR: DB de test introuvable: $db\n";
    echo "Lancez d'abord 'php setup_test_db.php' pour créer la DB de test.\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$passed = 0;
$failed = 0;

function test_run($name, $fn) {
    global $passed, $failed;
    $result = $fn();
    $ok = ($result === true);
    if ($ok) { $passed++; } else { $failed++; }
    $status = $ok ? 'OK' : 'FAIL';
    echo "  [$status] $name" . ($ok ? '' : " -- $result") . "\n";
}

echo "===================================================\n";
echo "  Tests Cycle filled_by (Option A)\n";
echo "===================================================\n\n";

// --- 16.1 : Schéma ---
echo "-- 16.1 Schéma --\n";

$cols = $pdo->query("PRAGMA table_info(form_fields)")->fetchAll(PDO::FETCH_ASSOC);
$col_names = array_column($cols, 'name');

test_run('Colonne filled_by existe', function() use ($col_names) {
    return in_array('filled_by', $col_names) ? true : "Colonnes: " . implode(', ', $col_names);
});

test_run('Colonne validator_step existe', function() use ($col_names) {
    return in_array('validator_step', $col_names) ? true : "Colonnes: " . implode(', ', $col_names);
});

$has_table = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='submission_validator_data'")->fetchColumn();
test_run('Table submission_validator_data existe', function() use ($has_table) {
    return ($has_table === 'submission_validator_data') ? true : 'Table manquante';
});

// --- 16.2 : Données existantes ---
echo "\n-- 16.2 Données existantes --\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM form_fields WHERE filled_by = 'validator'");
$validator_count = (int)$stmt->fetchColumn();

test_run('Au moins 1 champ validator existe', function() use ($validator_count) {
    return $validator_count > 0 ? true : "0 champ validator trouvé";
});

$stmt = $pdo->query("SELECT field_name, filled_by, validator_step FROM form_fields WHERE filled_by = 'validator' ORDER BY field_name");
$validator_fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
$field_list = implode(', ', array_column($validator_fields, 'field_name'));
echo "    -> Champs validator: $field_list\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM form_fields WHERE (filled_by IS NULL OR filled_by = 'demandeur')");
$demandeur_count = (int)$stmt->fetchColumn();

test_run('Au moins 1 champ demandeur existe', function() use ($demandeur_count) {
    return $demandeur_count > 0 ? true : "0 champ demandeur";
});

// --- 16.3 : Export JSON ---
echo "\n-- 16.3 Export JSON --\n";

$stmt = $pdo->query("SELECT * FROM form_fields LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

test_run('SELECT * retourne filled_by', function() use ($row) {
    return isset($row['filled_by']) ? true : 'filled_by absent';
});

test_run('SELECT * retourne validator_step', function() use ($row) {
    return isset($row['validator_step']) ? true : 'validator_step absent';
});

echo "    -> Exemple: field={$row['field_name']}, filled_by={$row['filled_by']}, step={$row['validator_step']}\n";

// --- 16.4 : Sauvegarde/relecture validator data ---
echo "\n-- 16.4 Sauvegarde et relecture validator data --\n";

// Vérifier les 4 nouvelles colonnes d'audit (P1-A / v14)
$svd_cols = $pdo->query("PRAGMA table_info(submission_validator_data)")->fetchAll(PDO::FETCH_ASSOC);
$svd_col_names = array_column($svd_cols, 'name');

test_run('Colonne step_id existe (audit v14)', function() use ($svd_col_names) {
    return in_array('step_id', $svd_col_names, true) ? true : 'Colonne step_id manquante';
});

test_run('Colonne step_label existe (audit v14)', function() use ($svd_col_names) {
    return in_array('step_label', $svd_col_names, true) ? true : 'Colonne step_label manquante';
});

test_run('Colonne filled_by_email existe (audit v14)', function() use ($svd_col_names) {
    return in_array('filled_by_email', $svd_col_names, true) ? true : 'Colonne filled_by_email manquante';
});

test_run('Colonne token_id existe (audit v14)', function() use ($svd_col_names) {
    return in_array('token_id', $svd_col_names, true) ? true : 'Colonne token_id manquante';
});

// Vérifier l'index UNIQUE sur (submission_id, field_name)
$unique_idx = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='submission_validator_data' AND sql LIKE '%submission_id, field_name%'")->fetchColumn();
test_run('Index UNIQUE sur (submission_id, field_name) existe', function() use ($unique_idx) {
    return ($unique_idx !== false) ? true : 'Index UNIQUE manquant';
});

$sub_id = bin2hex(random_bytes(16));
$step_id_test = bin2hex(random_bytes(8));
$token_id_test = bin2hex(random_bytes(8));
$validator_email = 'validator@test.local';

$pdo->exec("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at) VALUES ('$sub_id', 'onboarding', '{\"nom_complet\":\"Test\"}', 'test@e2e.test', datetime('now'))");

// Insert données validator directement AVEC les colonnes d'audit (P1-A / v14)
$pdo->prepare("INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?, ?, ?, ?)")
    ->execute(['test_v1', $sub_id, 'decision_validation', 'Décision', 'select', 'Accepté', 'validator', $step_id_test, 'Validation manager', $validator_email, $token_id_test]);

$stmt = $pdo->prepare("SELECT * FROM submission_validator_data WHERE submission_id = ?");
$stmt->execute([$sub_id]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

test_run('Données validator persistées', function() use ($data) {
    return !empty($data) ? true : "Aucune donnée";
});

test_run('Valeur correcte enregistrée', function() use ($data) {
    foreach ($data as $d) {
        if ($d['field_name'] === 'decision_validation' && $d['value'] === 'Accepté') return true;
    }
    return "Valeur non trouvée";
});

test_run('step_id persisté (audit)', function() use ($data, $step_id_test) {
    foreach ($data as $d) {
        if ($d['field_name'] === 'decision_validation' && ($d['step_id'] ?? '') === $step_id_test) return true;
    }
    return "step_id manquant ou incorrect";
});

test_run('step_label persisté (audit)', function() use ($data) {
    foreach ($data as $d) {
        if ($d['field_name'] === 'decision_validation' && ($d['step_label'] ?? '') === 'Validation manager') return true;
    }
    return "step_label manquant ou incorrect";
});

test_run('filled_by_email persisté (audit)', function() use ($data, $validator_email) {
    foreach ($data as $d) {
        if ($d['field_name'] === 'decision_validation' && ($d['filled_by_email'] ?? '') === $validator_email) return true;
    }
    return "filled_by_email manquant ou incorrect";
});

test_run('token_id persisté (audit)', function() use ($data, $token_id_test) {
    foreach ($data as $d) {
        if ($d['field_name'] === 'decision_validation' && ($d['token_id'] ?? '') === $token_id_test) return true;
    }
    return "token_id manquant ou incorrect";
});

// Upsert via ON CONFLICT(submission_id, field_name) (v14)
$pdo->prepare("INSERT INTO submission_validator_data (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?, ?, ?, ?) ON CONFLICT(submission_id, field_name) DO UPDATE SET value=excluded.value, filled_by=excluded.filled_by, step_id=excluded.step_id, filled_by_email=excluded.filled_by_email, token_id=excluded.token_id")
    ->execute(['test_v1_bis', $sub_id, 'decision_validation', 'Décision', 'select', 'Accepté avec réserves', 'validator', $step_id_test, 'Validation manager', $validator_email, $token_id_test]);

$stmt = $pdo->prepare("SELECT value, step_id, filled_by_email, token_id FROM submission_validator_data WHERE submission_id = ? AND field_name = 'decision_validation'");
$stmt->execute([$sub_id]);
$upserted = $stmt->fetch(PDO::FETCH_ASSOC);

test_run('Upsert met à jour la valeur', function() use ($upserted) {
    return (($upserted['value'] ?? '') === 'Accepté avec réserves') ? true : "Valeur: " . ($upserted['value'] ?? '');
});

test_run('Upsert préserve les colonnes d\'audit', function() use ($upserted, $step_id_test, $validator_email, $token_id_test) {
    if (($upserted['step_id'] ?? '') !== $step_id_test) return "step_id perdu: " . ($upserted['step_id'] ?? '');
    if (($upserted['filled_by_email'] ?? '') !== $validator_email) return "filled_by_email perdu";
    if (($upserted['token_id'] ?? '') !== $token_id_test) return "token_id perdu";
    return true;
});

// Vérifier qu'il n'y a qu'une seule ligne (l'UPSERT n'a pas dupliqué)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_validator_data WHERE submission_id = ? AND field_name = 'decision_validation'");
$stmt->execute([$sub_id]);
$count_after_upsert = (int)$stmt->fetchColumn();

test_run('UPSERT n\'a pas dupliqué la ligne', function() use ($count_after_upsert) {
    return $count_after_upsert === 1 ? true : "Attendu 1 ligne, trouvé $count_after_upsert";
});

// Nettoyage
$pdo->exec("DELETE FROM submission_validator_data WHERE submission_id = '$sub_id'");
$pdo->exec("DELETE FROM attachments WHERE submission_id = '$sub_id'");
$pdo->exec("DELETE FROM tokens WHERE submission_id = '$sub_id'");
$pdo->exec("DELETE FROM submissions WHERE id = '$sub_id'");

// --- 16.5 : Validation import JSON ---
echo "\n-- 16.5 Validation import JSON --\n";

$test_json = json_encode([
    'schema_version' => '1.0',
    'form' => ['label' => 'Test', 'slug' => 'test'],
    'fields' => [
        ['label' => 'Nom', 'field_type' => 'text', 'field_name' => 'nom', 'filled_by' => 'validator', 'validator_step' => 'Étape 1'],
        ['label' => 'Prénom', 'field_type' => 'text', 'field_name' => 'prenom', 'filled_by' => 'demandeur'],
    ]
]);

$decoded = json_decode($test_json, true);

test_run('JSON filled_by=validator valide', function() use ($decoded) {
    $v = $decoded['fields'][0]['filled_by'] ?? '';
    return in_array($v, ['demandeur', 'validator']) ? true : "Value invalide: $v";
});

test_run('JSON filled_by invalide -> fallback demandeur', function() use ($decoded) {
    $decoded['fields'][0]['filled_by'] = 'invalido';
    $val = !empty($decoded['fields'][0]['filled_by']) ? $decoded['fields'][0]['filled_by'] : 'demandeur';
    if (!in_array($val, ['demandeur', 'validator'])) $val = 'demandeur';
    return ($val === 'demandeur') ? true : "Fallback: $val";
});

// --- 16.6 : Export JSON complet ---
echo "\n-- 16.6 Export JSON complet --\n";

$export_row = $pdo->query("SELECT * FROM form_fields ORDER BY ordre LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$export_data = [
    'label' => $export_row['label'],
    'field_type' => $export_row['field_type'],
    'field_name' => $export_row['field_name'],
    'filled_by' => $export_row['filled_by'] ?? 'demandeur',
    'validator_step' => $export_row['validator_step'] ?? '',
];
$export_json = json_encode($export_data, JSON_PRETTY_PRINT);

test_run('Export JSON inclut filled_by', function() use ($export_data) {
    return isset($export_data['filled_by']) ? true : 'Manquant';
});

test_run('Export JSON inclut validator_step', function() use ($export_data) {
    return isset($export_data['validator_step']) ? true : 'Manquant';
});

echo "    -> Export exemple:\n$export_json\n";

// --- Résumé ---
echo "\n===================================================\n";
echo "  $passed OK | $failed ÉCHECS\n";
echo "===================================================\n";

exit($failed > 0 ? 1 : 0);
