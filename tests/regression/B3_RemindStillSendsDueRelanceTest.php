<?php
declare(strict_types=1);
/**
 * B3 — remind.php : sortir l'envoi SMTP de la transaction SQLite ne doit pas
 * casser l'envoi d'une relance due ni sa persistance (garde d'écriture).
 *
 * Garde de caractérisation : le refactor B3 est sans changement de comportement
 * observable. Ce test est vert AVANT et APRÈS le refactor et garantit qu'une
 * relance due (token actif, sent_at > délai de relance) est bien envoyée et
 * enregistrée une seule fois (relance_count === 1, relance_at non NULL).
 *
 * Même technique que Bug12_RemindTimezoneOffsetTest.php : exécution de
 * remind.php en sous-processus (proc_open) avec APP_TEST_MODE=1 (DB de test +
 * mails interceptés).
 *
 * Note : la base de test partagée peut contenir d'autres tokens relançables
 * (données de démo seedées). Le test ne fige donc pas le total de relances du
 * script et cible spécifiquement le token fixture.
 *
 * Fichier : tests/regression/B3_RemindStillSendsDueRelanceTest.php
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression B3 (relance toujours envoyée hors transaction).
 *
 * @return bool True si succès, false si échec.
 */
function run_b3_test(): bool {
    $root = dirname(__DIR__, 2);
    $dbPath = $root . '/db/workflow_test.db';

    if (!is_file($dbPath)) {
        echo "  ⚠️  B3 — db/workflow_test.db introuvable (lancer vendor/bin/phpunit au moins une fois avant) — test skip\n";
        return true;
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $formId  = bin2hex(random_bytes(8));
    $stepId  = bin2hex(random_bytes(8));
    $subId   = bin2hex(random_bytes(8));
    $tokenId = bin2hex(random_bytes(8));
    $failures = [];
    // Restauration de l'environnement du process de test (le sous-processus
    // hérite de putenv — portable Windows/Linux, contrairement au préfixe
    // 'APP_TEST_MODE=1 php ...' interprété par cmd.exe).
    $previousTestMode = getenv('APP_TEST_MODE');

    try {
        // ── Fixtures : form/step/submission/token ────────────────────
        // relance_delai_h=48 / relance_max=3 explicites (colonnes par formulaire).
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, relance_delai_h, relance_max) VALUES (?, ?, 'B3 Test', '', 1, datetime('now'), 48, 3)")
            ->execute([$formId, 'b3-remind-test-' . $formId]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, '{}', 'b3remind@test.com', 'en_cours', datetime('now'), NULL)")
            ->execute([$subId, $formId]);

        // Token envoyé il y a 49h (UTC) — au-delà du délai de 48h → relance due.
        $sentAt = gmdate('Y-m-d H:i:s', strtotime('-49 hours'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, relance_count, invalidated_at, expires_at)
                        VALUES (?, ?, ?, 'b3remind@test.com', ?, ?, NULL, NULL, 0, NULL, ?)")
            ->execute([$tokenId, $subId, $stepId, bin2hex(random_bytes(16)), $sentAt, gmdate('Y-m-d H:i:s', strtotime('+7 days'))]);

        // ── Exécution de remind.php en sous-processus (DB test + mails interceptés) ──
        $cmd = 'php ' . escapeshellarg($root . '/remind.php');
        putenv('APP_TEST_MODE=1');
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $root);
        if (!is_resource($proc)) {
            $failures[] = 'proc_open() a échoué pour lancer remind.php';
        } else {
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            // Le script doit produire la ligne de synthèse.
            if (!preg_match('/\d+ relance\(s\) envoyée\(s\)\./', $stdout)) {
                $failures[] = "remind.php n'a pas produit la ligne de synthèse. stdout: " . trim($stdout) . " stderr: " . trim($stderr);
            }

            $row = $pdo->prepare("SELECT relance_count, relance_at FROM tokens WHERE id = ?");
            $row->execute([$tokenId]);
            $result = $row->fetch(PDO::FETCH_ASSOC);

            if ($result === false) {
                $failures[] = 'Token de test introuvable après exécution de remind.php';
            } else {
                if ((int) $result['relance_count'] !== 1) {
                    $failures[] = "Relance due non enregistrée exactement une fois — relance_count={$result['relance_count']}. stdout: " . trim($stdout);
                }
                if ($result['relance_at'] === null || $result['relance_at'] === '') {
                    $failures[] = 'relance_at doit être renseigné après une relance envoyée.';
                }
            }
        }

        if ($failures !== []) {
            echo "  ❌ B3 — " . count($failures) . " régression(s) détectée(s) :\n";
            foreach ($failures as $f) {
                echo "     - $f\n";
            }
            return false;
        }

        echo "  ✅ B3 — remind.php envoie et enregistre une relance due une seule fois (SMTP hors transaction)\n";
        return true;
    } finally {
        // ── Restauration env + nettoyage fixtures ──────────────────────
        if ($previousTestMode === false || $previousTestMode === '') {
            putenv('APP_TEST_MODE');
        } else {
            putenv('APP_TEST_MODE=' . $previousTestMode);
        }
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
    }
}

// Exécution directe (php tests/regression/B3_RemindStillSendsDueRelanceTest.php).
// Inclus par run_all.php → $argv[0] != ce fichier → pas de double exécution.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(run_b3_test() ? 0 : 1);
}