<?php
declare(strict_types=1);
/**
 * Bug 13 — MailService::send() et sendDetailed() étaient deux implémentations
 * PHPMailer entièrement dupliquées et divergentes
 *
 * send() (utilisée par TOUT le workflow métier réel : WorkflowEngine,
 * TokenService — demandes de validation, refus, délégations) ne configurait
 * NI l'authentification SMTP (SMTPAuth/Username/Password) NI le TLS/SSL
 * (SMTPSecure) — pourtant ce sont des réglages admin exposés et fonctionnels
 * (AdminSettingsRenderer). sendDetailed() (utilisée uniquement par le bouton
 * "tester l'email" de l'admin) configurait tout correctement.
 *
 * Si le serveur SMTP de production exige l'authentification ou le TLS
 * (quasi certain pour un relais gouvernemental), send() échouait
 * silencieusement (catch + error_log + return false) alors que le test
 * SMTP de l'admin réussissait — masquant le problème.
 *
 * Fix : send() délègue maintenant entièrement à sendDetailed(), seule
 * implémentation SMTP restante. sendDetailed() alimente aussi mail_log
 * (jusqu'ici jamais écrite malgré la page monitoring qui l'affiche).
 *
 * Ce test tourne HORS TEST_MODE (via un sous-processus + des constantes de
 * config pré-définies) car TEST_MODE court-circuite sendDetailed() avant
 * d'atteindre la logique réelle — impossible à exercer depuis un TestCase
 * PHPUnit classique (qui tourne toujours en TEST_MODE=1).
 *
 * Fichier : tests/regression/Bug13_MailServiceDelegationTest.php
 *
 * @package tests\regression
 */

/**
 * Lance le test de non-régression Bug 13.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug13_test(): bool {
    $root = dirname(__DIR__, 2);
    $failures = [];

    foreach (['dry_run', 'blocked'] as $scenario) {
        $scratchDb = sys_get_temp_dir() . '/bug13_probe_' . $scenario . '_' . getmypid() . '.db';
        @unlink($scratchDb);

        $cmd = sprintf(
            'env -u APP_TEST_MODE -u HTTP_X_TEST_MODE php %s %s %s',
            escapeshellarg($root . '/tests/regression/_mail_send_probe.php'),
            escapeshellarg($scenario),
            escapeshellarg($scratchDb)
        );
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $root);
        if (!is_resource($proc)) {
            $failures[] = "[$scenario] proc_open() a échoué";
            continue;
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // draine stderr (warnings PHP sans intérêt ici)
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // La sortie JSON est sur la dernière ligne non vide (les warnings PHP passent en stderr,
        // mais par prudence on ne prend que la dernière ligne de stdout).
        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        $jsonLine = end($lines);
        $decoded = $jsonLine !== false ? json_decode((string) $jsonLine, true) : null;

        if (!is_array($decoded) || !isset($decoded['send_bool'], $decoded['send_detailed']['success'])) {
            $failures[] = "[$scenario] sortie JSON invalide : " . var_export($stdout, true);
            @unlink($scratchDb);
            continue;
        }

        // 1. send() et sendDetailed() doivent être cohérents (delegation).
        if ($decoded['send_bool'] !== $decoded['send_detailed']['success']) {
            $failures[] = "[$scenario] send()={$decoded['send_bool']} incohérent avec sendDetailed()['success']="
                . ($decoded['send_detailed']['success'] ? 'true' : 'false') . ' — send() ne délègue plus à sendDetailed() ?';
        }

        // 2. mail_log doit contenir 2 lignes (une par appel : send() + sendDetailed()).
        if (is_file($scratchDb)) {
            $pdo = new PDO('sqlite:' . $scratchDb);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM mail_log')->fetchColumn();
            if ($count !== 2) {
                $failures[] = "[$scenario] mail_log contient $count ligne(s), 2 attendues — persistance cassée ?";
            }
            $pdo = null;
        } else {
            $failures[] = "[$scenario] DB de la sonde introuvable — mail_log jamais créée";
        }

        @unlink($scratchDb);
    }

    if (!empty($failures)) {
        echo "  ❌ Bug13 — " . count($failures) . " régression(s) détectée(s) :\n";
        foreach ($failures as $f) {
            echo "     - $f\n";
        }
        return false;
    }

    echo "  ✅ Bug13 — send() délègue correctement à sendDetailed() et mail_log est alimentée\n";
    return true;
}
