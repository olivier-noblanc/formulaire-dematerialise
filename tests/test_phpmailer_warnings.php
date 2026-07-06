<?php
declare(strict_types=1);
/**
 * test_phpmailer_warnings.php — Test que l'instanciation de PHPMailer ne
 * génère AUCUN warning/notide/deprecated PHP.
 *
 * Bug historique : lib/mail.php ligne 179 faisait `$mail->Timelimit = 15;`
 * mais `Timelimit` est une propriété de la classe SMTP, PAS PHPMailer.
 * En PHP 8.4, créer une propriété dynamique sur PHPMailer déclenche :
 *   "Deprecated: Creation of dynamic property PHPMailer\PHPMailer\PHPMailer::$Timelimit is deprecated"
 *
 * Ce test n'était pas couvert par les tests existants parce que :
 *  - TEST_MODE intercepte send_mail() avant l'instanciation de PHPMailer
 *  - mail_dry_run=1 intercepte aussi avant
 *  - Les tests e2e soumettent un formulaire mais en dry-run → pas d'instanciation
 *  Le warning n'apparaissait qu'en production avec SMTP réel.
 *
 * Solution : ce test instancie PHPMailer directement, set les mêmes propriétés
 * que send_mail_detailed(), et capture stderr pour vérifier qu'aucun warning
 * n'est émis.
 *
 * Usage : php tests/test_phpmailer_warnings.php
 */

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/../helpers.php';

// Charger PHPMailer
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

echo "── Test PHPMailer : aucun warning/deprecated à l'instanciation ──\n";

$tests_passed = 0;
$tests_failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $tests_passed, $tests_failed;
    if ($ok) {
        echo "  ✅ $name\n";
        $tests_passed++;
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $tests_failed++;
    }
}

// ── Test 1 : instancier PHPMailer et set les propriétés comme send_mail_detailed() ──
echo "\n── Test 1 : set des propriétés PHPMailer (comme send_mail_detailed) ──\n";

// Capturer stderr (warnings PHP)
$stderr_capture = '';
$old_error_handler = set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) use (&$stderr_capture): bool {
    // Capturer tous les warnings/notices/deprecated
    $stderr_capture .= "[$errno] $errstr at $errfile:$errline\n";
    return true;  // Ne pas propager au handler par défaut
});

try {
    $mail = new PHPMailer(true);

    // Set exactement les mêmes propriétés que send_mail_detailed() dans lib/mail.php
    $mail->isSMTP();
    $mail->Host       = 'smtp.example.com';
    $mail->Port       = 25;
    $mail->SMTPAuth   = false;
    $mail->SMTPSecure = '';
    $mail->SMTPAutoTLS = false;

    // Timeout (propriété de PHPMailer — OK)
    $mail->Timeout = 30;

    // Timelimit (propriété de SMTP, PAS PHPMailer)
    // AVANT (bug) : $mail->Timelimit = 15;  → warning PHP 8.4
    // APRÈS (fix) : $mail->getSMTPInstance()->Timelimit = 15;
    $mail->getSMTPInstance()->Timelimit = 15;

    // Debug
    $mail->SMTPDebug = 3;
    $smtp_log_buf = [];
    $mail->Debugoutput = function(string $str, int $level) use (&$smtp_log_buf): void {
        $smtp_log_buf[] = '[' . $level . '] ' . rtrim($str);
    };

    $mail->CharSet = 'UTF-8';
    $mail->setFrom('from@example.com', 'Test');
    $mail->addAddress('to@example.com');
    $mail->isHTML(true);
    $mail->Subject = 'Test';
    $mail->Body = '<p>Test</p>';
    // NE PAS appeler send() — on veut juste vérifier l'absence de warnings
    // à la configuration, pas faire un vrai envoi SMTP.

    check(
        "Aucun warning/deprecated émis pendant la configuration de PHPMailer",
        $stderr_capture === '',
        $stderr_capture !== '' ? "Warnings capturés :\n$stderr_capture" : ''
    );

    check(
        "Timelimit est bien set sur l'instance SMTP (pas sur PHPMailer)",
        $mail->getSMTPInstance()->Timelimit === 15,
        'Timelimit non transmis à SMTP instance'
    );

    check(
        "Timeout est set sur PHPMailer",
        $mail->Timeout === 30,
        'Timeout non set'
    );
} catch (\Throwable $e) {
    check("Instanciation PHPMailer sans exception", false, $e->getMessage());
} finally {
    restore_error_handler();
}

// ── Test 2 : vérifier que l'ancien pattern (propriété dynamique) génère un warning ──
echo "\n── Test 2 : vérification que l'ancien pattern (propriété dynamique) est détecté ──\n";

$stderr_capture2 = '';
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) use (&$stderr_capture2): bool {
    $stderr_capture2 .= "[$errno] $errstr\n";
    return true;
});

try {
    $mail2 = new PHPMailer(true);
    // L'ANCIEN pattern (bug) : setter Timelimit directement sur PHPMailer
    // Doit générer un deprecated en PHP 8.4
    @$mail2->Timelimit = 15;  // @ supprime — on veut juste vérifier que sans @, ça warn

    // Retester sans @ pour voir si le warning est émis
    $stderr_capture2 = '';
    $mail3 = new PHPMailer(true);
    $mail3->Timelimit = 15;  // SANS @ — devrait générer le warning

    $has_deprecated = strpos($stderr_capture2, 'deprecated') !== false
                   || strpos($stderr_capture2, 'Deprecated') !== false;
    check(
        "L'ancien pattern \$mail->Timeliment = 15 génère bien un deprecated en PHP 8.4",
        $has_deprecated,
        $has_deprecated ? '' : "Aucun deprecated émis (PHP < 8.4 ?). stderr=$stderr_capture2"
    );
} catch (\Throwable $e) {
    check("Pattern dynamique détecté", false, $e->getMessage());
} finally {
    restore_error_handler();
}

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSULTATS : $tests_passed réussi(s) / $tests_failed échoué(s) / " . ($tests_passed + $tests_failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($tests_failed > 0 ? 1 : 0);
