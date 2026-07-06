<?php
/**
 * Appliquer les migrations manquantes sur workflow_test.db
 * (simulation de ce que ferait populate_samples + migration v13)
 */

$db = '/workspace/formulaire-dematerialise/db/workflow_test.db';
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Créer la table submission_validator_data (manquante)
$pdo->exec("CREATE TABLE IF NOT EXISTS submission_validator_data (
    id TEXT PRIMARY KEY NOT NULL,
    submission_id TEXT NOT NULL,
    field_name TEXT NOT NULL,
    field_label TEXT NOT NULL,
    field_type TEXT NOT NULL DEFAULT 'text',
    value TEXT,
    filled_by TEXT NOT NULL DEFAULT 'validator',
    filled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
)");
echo "✓ Table submission_validator_data créée\n";

// 2. Trouver les formulaires existants
$forms = $pdo->query("SELECT id, slug FROM forms WHERE actif = 1")->fetchAll(PDO::FETCH_ASSOC);
echo "✓ " . count($forms) . " formulaires actifs trouvés\n";

// 3. Ajouter des champs validator pour chaque formulaire
$validator_fields = [
    'onboarding' => [
        ['label' => 'Décision de validation', 'field_type' => 'select', 'field_name' => 'decision_validation', 'validator_step' => 'Étape 1'],
        ['label' => 'Observations du validateur', 'field_type' => 'textarea', 'field_name' => 'observations_validateur', 'validator_step' => 'Étape 1'],
    ],
    'outboarding' => [
        ['label' => 'Bilan de départ', 'field_type' => 'textarea', 'field_name' => 'bilan_depart', 'validator_step' => 'Étape 2'],
    ],
];

$insert_field = $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, filled_by, validator_step) VALUES (?, ?, ?, ?, ?, '', 0, ?, 'Validation', 'validator', ?)");

$count = 0;
foreach ($forms as $form) {
    $slug = $form['slug'];
    if (isset($validator_fields[$slug])) {
        foreach ($validator_fields[$slug] as $vf) {
            $field_id = bin2hex(random_bytes(8));
            $ordre = 100 + $count;
            $insert_field->execute([$field_id, $form['id'], $vf['label'], $vf['field_type'], $vf['field_name'], $ordre, $vf['validator_step']]);
            echo "  → Champ validator '{$vf['field_name']}' ajouté au formulaire '{$slug}'\n";
            $count++;
        }
    }
}

echo "\n" . $count . " champs validator ajoutés.\n";

// 4. Vérifier le résultat
echo "\n=== Vérification ===\n";
$stmt = $pdo->query("SELECT filled_by, COUNT(*) as cnt FROM form_fields GROUP BY filled_by");
while ($row = $stmt->fetch()) {
    echo "  filled_by={$row['filled_by']}: {$row['cnt']} champs\n";
}

echo "\n✅ Migration test DB terminée\n";
