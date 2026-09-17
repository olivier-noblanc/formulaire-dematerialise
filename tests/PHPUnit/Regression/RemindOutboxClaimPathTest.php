<?php
declare(strict_types=1);

namespace App\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * BUG1 — chemin cross cron (remind.php) + outbox (replayOutbox).
 *
 * Le cron de relance et le worker d'outbox partagent le même écrit : un échec
 * SMTP réessayable produit une ligne mail_log rejouable. Si remind.php libérait
 * le créneau (relance_count) sur cet échec, le rejeu renverrait l'email avec le
 * compteur rabattu — contournement du plafond relance_max (relance fantôme).
 *
 * Ce test exécute le vrai remind.php PUIS MailService::replayOutbox() (sonde
 * tests/regression/_remind_outbox_probe.php, hors TEST_MODE) et vérifie que le
 * claim reste revendiqué à travers les deux étapes.
 *
 * Fichier : tests/PHPUnit/Regression/RemindOutboxClaimPathTest.php
 */
final class RemindOutboxClaimPathTest extends TestCase
{
    private string $root;
    private string $probe;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $this->probe = $this->root . '/tests/regression/_remind_outbox_probe.php';
        if (!is_file($this->probe)) {
            self::markTestSkipped('sonde _remind_outbox_probe.php introuvable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runProbe(): array
    {
        $db = sys_get_temp_dir() . '/remind_outbox_' . getmypid() . '_' . uniqid() . '.db';
        $resultFile = $db . '.result.json';

        $previous = getenv('APP_TEST_MODE');
        putenv('APP_TEST_MODE'); // sous-processus HORS TEST_MODE (cf. core_bootstrap)

        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($this->probe) . ' '
            . escapeshellarg($db) . ' '
            . escapeshellarg($resultFile);

        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes, $this->root);
            if (!is_resource($proc)) {
                self::fail('proc_open() a échoué pour lancer la sonde remind+outbox');
            }
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        } finally {
            if ($previous === false || $previous === '') {
                putenv('APP_TEST_MODE');
            } else {
                putenv('APP_TEST_MODE=' . $previous);
            }
        }

        $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
        foreach ([$db, $db . '-wal', $db . '-shm', $resultFile] as $f) {
            @unlink($f);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail(
                "Sonde remind+outbox : sortie JSON invalide (exit=$exit). "
                . "stdout: " . trim($stdout) . ' stderr: ' . trim($stderr)
            );
        }

        if (isset($decoded['probe_error'])) {
            self::fail('Sonde remind+outbox en erreur : ' . $decoded['probe_error']);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testRelanceClaimIsKeptAcrossRetryableSmtpFailureThenOutboxReplay(): void
    {
        $out = $this->runProbe();

        // 1. remind.php : échec SMTP réessayable → le claim est CONSERVÉ.
        self::assertSame(
            1,
            $out['relance_count_after_remind'],
            'un échec SMTP réessayable ne doit pas rabattre relance_count (claim conservé pour l\'outbox)'
        );
        self::assertNotNull($out['relance_at_after_remind'], 'le créneau revendiqué doit poser relance_at');

        $rowAfterRemind = $out['row_after_remind'];
        self::assertIsArray($rowAfterRemind, 'le write-ahead doit avoir écrit une ligne mail_log');
        self::assertSame('error', $rowAfterRemind['status'], 'un SMTP injoignable est un échec réessayable');
        self::assertNotNull($rowAfterRemind['body_html'], 'le corps HTML doit être conservé pour le rejeu');
        self::assertSame(1, (int) $rowAfterRemind['attempts']);
        self::assertNotNull($rowAfterRemind['next_retry_at'], 'le rejeu doit être planifié');

        // 2. replayOutbox : la relance repart en file sans rabattre le compteur.
        $stats = $out['replay_stats'];
        self::assertIsArray($stats);
        self::assertGreaterThanOrEqual(1, $stats['processed'], 'la ligne en erreur doit être reprise par l\'outbox');
        self::assertGreaterThanOrEqual(1, $stats['error'], 'SMTP toujours injoignable → nouvel échec réessayable');

        self::assertSame(
            1,
            $out['relance_count_after_replay'],
            'le rejeu outbox ne doit jamais rabattre le compteur de relance revendiqué'
        );

        $rowAfterReplay = $out['row_after_replay'];
        self::assertIsArray($rowAfterReplay);
        self::assertSame('error', $rowAfterReplay['status']);
        self::assertSame(2, (int) $rowAfterReplay['attempts'], 'la revendication outbox incrémente attempts');
    }
}