<?php
declare(strict_types=1);
/**
 * Bug 12 — remind.php interprétait sent_at/relance_at sans fuseau horaire
 * explicite, faussant le calcul du délai de relance
 *
 * Symptôme : `$sent = new DateTimeImmutable($tok['sent_at']);` interprète
 * la chaîne stockée (toujours UTC — écrite via SQLite datetime('now')) selon
 * le fuseau par défaut du serveur PHP. En production (Europe/Paris,
 * UTC+1/+2), cela décale l'instant "réel" reconstruit de 1 à 2h, ce qui
 * fausse d'autant le calcul de `$depuis` (heures écoulées depuis le dernier
 * envoi) utilisé pour décider si une relance doit partir.
 *
 * Même classe de bug que le #12 du tableau d'audit (déjà fixé dans
 * alert_check.php), retrouvée ici dans remind.php — jamais corrigée dans ce
 * script jumeau.
 *
 * Fix v10.21.x : `new DateTimeImmutable($str, new DateTimeZone('UTC'))`
 * pour $now, $sent et $last_ref.
 *
 * Ce test simule un serveur en Europe/Paris (`php -d date.timezone=...`) et
 * vérifie qu'un token envoyé il y a 47h (< délai de relance de 48h) ne
 * déclenche PAS de relance — avant le fix, le décalage de fuseau le faisait
 * apparaître comme envoyé il y a ~49h et déclenchait une relance prématurée.
 *
 * Fichier : tests/regression/Bug12_RemindTimezoneOffsetTest.php
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression Bug 12.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug12_test(): bool {
    $root = dirname(__DIR__, 2);
    $dbPath = $root . '/db/workflow_test.db';

    if (!is_file($dbPath)) {
        echo "  ⚠️  Bug12 — db/workflow_test.db introuvable (lancer vendor/bin/phpunit au moins une fois avant) — test skip\n";
        return true;
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Sauvegarde des settings existants pour restauration ──────────
    $savedSettings = $pdo->query("SELECT key, value FROM settings WHERE key IN ('delai_relance_h', 'relance_max', 'mail_dry_run')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    $formId = bin2hex(random_bytes(8));
    $stepId = bin2hex(random_bytes(8));
    $subId  = bin2hex(random_bytes(8));
    $tokenId = bin2hex(random_bytes(8));
    $failures = [];

    try {
        // ── Fixtures : settings ───────────────────────────────────────
        $setSetting = function (string $key, string $value) use ($pdo): void {
            $pdo->prepare("INSERT INTO settings (key, value, updated_at, updated_by) VALUES (?, ?, datetime('now'), 'bug12_test')
                            ON CONFLICT(key) DO UPDATE SET value = excluded.value")
                ->execute([$key, $value]);
        };
        $setSetting('delai_relance_h', '48');
        $setSetting('relance_max', '3');
        $setSetting('mail_dry_run', '1'); // évite tout envoi SMTP réel

        // ── Fixtures : form/step/submission/token ─────────────────────
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, 'Bug12 Test', '', 1, datetime('now'))")
            ->execute([$formId, 'bug12-test-' . $formId]);
        $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, 'Validation', 1, 1, '')")
            ->execute([$stepId, $formId]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, '{}', 'bug12@test.com', 'en_cours', datetime('now'), NULL)")
            ->execute([$subId, $formId]);

        // Token envoyé il y a 47h (UTC) — sous le seuil de 48h, aucune relance attendue.
        $sentAt = gmdate('Y-m-d H:i:s', strtotime('-47 hours'));
        $pdo->prepare("INSERT INTO tokens (id, submission_id, step_id, email, token, sent_at, done_at, relance_at, relance_count, expires_at)
                        VALUES (?, ?, ?, 'bug12@test.com', ?, ?, NULL, NULL, 0, ?)")
            ->execute([$tokenId, $subId, $stepId, bin2hex(random_bytes(16)), $sentAt, gmdate('Y-m-d H:i:s', strtotime('+7 days'))]);

        // ── Exécution de remind.php en sous-processus, serveur simulé Europe/Paris ──
        $cmd = sprintf(
            'APP_TEST_MODE=1 php -d date.timezone=Europe/Paris %s',
            escapeshellarg($root . '/remind.php')
        );
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

            $row = $pdo->prepare("SELECT relance_count, relance_at FROM tokens WHERE id = ?");
            $row->execute([$tokenId]);
            $result = $row->fetch(PDO::FETCH_ASSOC);

            if ($result === false) {
                $failures[] = 'Token de test introuvable après exécution de remind.php';
            } elseif ((int) $result['relance_count'] !== 0) {
                $failures[] = "Relance envoyée prématurément (47h < délai 48h) — relance_count={$result['relance_count']} "
                    . "(décalage de fuseau Europe/Paris non corrigé). stdout: " . trim($stdout);
            }
        }

        if (!empty($failures)) {
            echo "  ❌ Bug12 — " . count($failures) . " régression(s) détectée(s) :\n";
            foreach ($failures as $f) {
                echo "     - $f\n";
            }
            return false;
        }

        echo "  ✅ Bug12 — remind.php calcule correctement le délai de relance en Europe/Paris (fuseau UTC explicite préservé)\n";
        return true;
    } finally {
        // ── Nettoyage fixtures ─────────────────────────────────────────
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        $pdo->prepare("DELETE FROM steps WHERE id = ?")->execute([$stepId]);
        $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        foreach (['delai_relance_h', 'relance_max', 'mail_dry_run'] as $key) {
            if (isset($savedSettings[$key])) {
                $pdo->prepare("UPDATE settings SET value = ? WHERE key = ?")->execute([$savedSettings[$key], $key]);
            } else {
                $pdo->prepare("DELETE FROM settings WHERE key = ?")->execute([$key]);
            }
        }
    }
}
