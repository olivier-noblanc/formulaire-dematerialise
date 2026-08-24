<?php
declare(strict_types=1);

/**
 * seed_cancel_submission.php — Fixture pour cancel-submission.spec.js.
 *
 * Insère UNE soumission au statut "en_cours" pour le formulaire par défaut,
 * afin que le dashboard admin affiche un lien d'annulation (le bouton
 * "Annuler" n'apparaît que pour les soumissions au statut EnCours, à
 * l'intérieur du bloc <details> de chaque ligne — cf. submission_detail.php).
 *
 * Le nom du demandeur (data.nom) et l'email (submitted_by) sont passés en
 * arguments pour être uniques par exécution : le test localise le <details>
 * correspondant via ce nom et évite ainsi les collisions avec les soumissions
 * d'exécutions précédentes (jamais nettoyées, cf. full_submission_flow.spec.js).
 *
 * Usage : php tests/e2e/seed_cancel_submission.php <nom> <email>
 *   <nom>   nom (unique) du demandeur, utilisé pour repérer le <details>
 *   <email> adresse submitted_by du demandeur
 *
 * Affiche l'UUID de la soumission créée sur stdout.
 * Suppose que db/workflow.db existe et contient au moins un formulaire
 * (schema_initial.php a déjà tourné) — à lancer après startTestServer().
 */

require_once __DIR__ . '/../../helpers.php';

$nom   = $argv[1] ?? 'TestCancel';
$email = $argv[2] ?? 'cancel@test.local';

$pdo = \App\Core\App::db()->getPdo();

$formId = $pdo->query('SELECT id FROM forms ORDER BY label ASC LIMIT 1')->fetchColumn();
if ($formId === false) {
    fwrite(STDERR, "seed_cancel_submission.php : aucun formulaire trouvé — schema_initial.php n'a pas tourné ?\n");
    exit(1);
}

$uuid = generate_uuid();
$data = json_encode(['nom' => $nom], JSON_UNESCAPED_UNICODE);

$stmt = $pdo->prepare(
    "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
     VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')"
);
if (!$stmt->execute([$uuid, $formId, $data, $email])) {
    fwrite(STDERR, "seed_cancel_submission.php : échec de l'INSERT\n");
    exit(1);
}

echo $uuid . "\n";
