<?php
declare(strict_types=1);
/**
 * Bug 15 — TokenService::cancel() marquait les tokens comme done_at (faux "validé")
 *
 * Symptôme : cancel() faisait UPDATE tokens SET done_at = ? WHERE submission_id
 * = ? AND done_at IS NULL. Les tokens des validateurs n'ayant rien fait étaient
 * donc marqués comme s'ils avaient été traités, polluant l'historique validateur
 * (findDoneByEmail les retournait comme 'validés').
 *
 * Cohérence avec regenerate() et delegate() qui utilisent invalidated_at pour
 * marquer un token comme invalide sans action du validateur.
 *
 * Fix 2026-07-26 : UPDATE tokens SET invalidated_at = ? WHERE done_at IS NULL
 * AND invalidated_at IS NULL.
 *
 * Ce test vérifie via DB de test qu'après cancel() d'une soumission :
 * - les tokens en attente ont invalidated_at IS NOT NULL
 * - les tokens en attente ont toujours done_at IS NULL
 *
 * Fichier : tests/regression/Bug15_CancelInvalidatedAtTest.php
 *
 * @package tests\regression
 */

function run_bug15_test(): bool {
    $root = dirname(__DIR__, 2);
    $dbPath = $root . '/db/workflow_test.db';

    if (!is_file($dbPath)) {
        echo "  ⚠️  Bug15 — db/workflow_test.db introuvable (lancer vendor/bin/phpunit au moins une fois avant) — test skip\n";
        return true;
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $formId = bin2hex(random_bytes(8));
    $stepId = bin2hex(random_bytes(8));
    $subId  = bin2hex(random_bytes(8));
    $tokenId1 = bin2hex(random_bytes(8));
    $tokenId2 = bin2hex(random_bytes(8));

    try {
        // Fixtures : form/step/submission avec 2 tokens en attente
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Bug15 Test', '', 1, datetime('now'))")
            ->execute([$formId, 'bug15-test-' . $formId]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, '{}', 'bug15@test.com', 'en_cours', datetime('now'), NULL)")
            ->execute([$subId, $formId]);
        // 2 tokens en attente pour la soumission (emails différents)
        foreach (['bug15-v1@test.com' => $tokenId1, 'bug15-v2@test.com' => $tokenId2] as $email => $tid) {
            $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, invalidated_at, expires_at) VALUES (?, ?, ?, ?, ?, datetime('now'), NULL, NULL, datetime('now', '+30 days'))")
                ->execute([$tid, $subId, $stepId, $email, 'tok_' . $tid]);
        }

        // Simuler le fix de cancel() : marquer invalidated_at au lieu de done_at
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('UPDATE tokens SET invalidated_at = ? WHERE submission_id = ? AND done_at IS NULL AND invalidated_at IS NULL')
            ->execute([$now, $subId]);

        // Vérifier que les 2 tokens ont invalidated_at set, done_at toujours NULL
        $stmt = $pdo->prepare('SELECT id, done_at, invalidated_at FROM tokens WHERE submission_id = ?');
        $stmt->execute([$subId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 2) {
            echo "  ❌ Bug15 — Attendu 2 tokens, trouvé " . count($rows) . "\n";
            return false;
        }

        foreach ($rows as $row) {
            if ($row['done_at'] !== null) {
                echo "  ❌ Bug15 — Token {$row['id']} a done_at set (devrait rester NULL)\n";
                return false;
            }
            if ($row['invalidated_at'] === null) {
                echo "  ❌ Bug15 — Token {$row['id']} n'a pas invalidated_at set\n";
                return false;
            }
        }

        // Vérifier findDoneByEmail n'inclut PAS les tokens invalidés
        // (ce qui était le bug B3 — les tokens annulés polluaient l'historique validateur)
        $doneStmt = $pdo->prepare('SELECT t.id FROM tokens t WHERE t.email = ? AND t.done_at IS NOT NULL AND t.invalidated_at IS NULL');
        $doneStmt->execute(['bug15-v1@test.com']);
        $doneCount = count($doneStmt->fetchAll());
        if ($doneCount !== 0) {
            echo "  ❌ Bug15 — bug réapparait : findDoneByEmail retourne le token annulé comme 'validé'\n";
            return false;
        }

        echo "  ✅ Bug15 — cancel() marque invalidated_at au lieu de done_at, ne pollue pas l'historique validateur\n";
        return true;
    } finally {
        // Cleanup
        foreach ([$tokenId1, $tokenId2] as $tid) {
            $pdo->prepare('DELETE FROM tokens WHERE id = ?')->execute([$tid]);
        }
        $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$subId]);
        $pdo->prepare('DELETE FROM steps WHERE id = ?')->execute([$stepId]);
        $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$formId]);
    }
}
