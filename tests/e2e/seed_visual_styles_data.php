<?php
declare(strict_types=1);

/**
 * seed_visual_styles_data.php — Fixture pour visual_styles.spec.js.
 *
 * Insère quelques soumissions (en_cours ×2, valide, refuse) pour
 * l'utilisateur "testeur" afin que la barre de stats
 * (.stat.en-cours / .stat.valide / .stat.refuse) de my_submissions.php
 * soit visible. Elle ne s'affiche que si $totalCount > 0
 * (MySubmissionsRenderer.php, ligne ~34) — sans données, la base
 * fraîchement créée par la CI (schema_initial.php, sans historique réel)
 * laisse ces éléments absents, contrairement à l'environnement local
 * d'Olivier qui a un vrai historique de soumissions accumulé au fil du
 * temps (cf. commentaire d'origine du test : "13 soumissions en DB de
 * test" — vrai en local, faux sur une base CI fraîche).
 *
 * Usage : php tests/e2e/seed_visual_styles_data.php
 * Suppose que le serveur de test a déjà démarré au moins une fois
 * (schema_initial.php doit avoir créé db/workflow.db et semé les
 * formulaires par défaut) — à lancer après startTestServer(), avant la
 * navigation Playwright.
 */

require_once __DIR__ . '/../../helpers.php';

$pdo = \App\Core\App::db()->getPdo();

$formId = $pdo->query('SELECT id FROM forms LIMIT 1')->fetchColumn();
if ($formId === false) {
    fwrite(STDERR, "seed_visual_styles_data.php : aucun formulaire trouvé — schema_initial.php n'a pas tourné ?\n");
    exit(1);
}

$domain = defined('SETTINGS_DEFAULTS') && isset(SETTINGS_DEFAULTS['email_domain'])
    ? SETTINGS_DEFAULTS['email_domain']
    : 'test.local';
$submitter = 'testeur@' . $domain;

$stmt = $pdo->prepare(
    "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
     VALUES (?, ?, '{}', ?, datetime('now'), ?)"
);

foreach (['en_cours', 'en_cours', 'valide', 'refuse'] as $status) {
    $stmt->execute([generate_uuid(), $formId, $submitter, $status]);
}

echo "seed_visual_styles_data.php : 4 soumissions créées pour {$submitter}\n";
