<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A2 — outbox SMTP write-ahead (MailService, hors TEST_MODE).
 *
 * PHPUnit tourne toujours en TEST_MODE : MailService::sendDetailed() y
 * court-circuite la logique réelle. Ces tests lancent donc la sonde
 * tests/regression/_mail_outbox_probe.php dans un sous-processus HORS TEST_MODE
 * (cf. Bug13_MailServiceDelegationTest) pour exercer le vrai chemin d'envoi :
 *   - `sent`        : la ligne passe pending → sent, corps conservé ;
 *   - `smtp_down`   : la ligne est écrite AVANT l'échec puis passée en error,
 *                     avec le corps HTML conservé et un rejeu planifié ;
 *   - `insert_fail` : si l'écriture durable échoue, AUCUN envoi n'a lieu et un
 *                     échec structuré (intervention technicien) est retourné.
 *
 * Fichier : tests/PHPUnit/MailOutboxWriteAheadTest.php
 */
final class MailOutboxWriteAheadTest extends TestCase
{
    private string $root;
    private string $probe;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->probe = $this->root . '/tests/regression/_mail_outbox_probe.php';
        if (!is_file($this->probe)) {
            self::markTestSkipped('sonde _mail_outbox_probe.php introuvable');
        }
    }

    /**
     * @return array{scenario: string, result: array{success:bool,error:string,smtp_log:string,status:string}, row: array<string,mixed>|null}
     */
    private function runProbe(string $scenario, string $dbPath): array
    {
        $previous = getenv('APP_TEST_MODE');
        putenv('APP_TEST_MODE'); // garante le sous-processus hors TEST_MODE (cf. core_bootstrap)

        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($this->probe) . ' '
            . escapeshellarg($scenario) . ' '
            . escapeshellarg($dbPath);

        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes, $this->root);
            if (!is_resource($proc)) {
                self::fail('proc_open() a échoué pour lancer la sonde outbox');
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

        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        $jsonLine = $lines !== [] ? end($lines) : false;
        $decoded = $jsonLine !== false ? json_decode((string) $jsonLine, true) : null;
        if (!is_array($decoded) || !isset($decoded['result'])) {
            self::fail(
                "Sonde outbox [$scenario] : sortie JSON invalide (exit=$exit). stdout: "
                . trim($stdout) . ' stderr: ' . trim($stderr)
            );
        }
        /** @var array{scenario: string, result: array{success:bool,error:string,smtp_log:string,status:string}, row: array<string,mixed>|null} $decoded */
        return $decoded;
    }

    private function scratchDb(string $tag): string
    {
        return sys_get_temp_dir() . '/mail_outbox_' . $tag . '_' . getmypid() . '_' . uniqid() . '.db';
    }

    private function cleanupDb(string $db): void
    {
        foreach ([$db, $db . '-wal', $db . '-shm', $db . '.smtp.ready'] as $f) {
            @unlink($f);
        }
    }

    public function testSentCycleMarksRowSentAndKeepsBody(): void
    {
        $db = $this->scratchDb('sent');
        try {
            $out = $this->runProbe('sent', $db);

            self::assertTrue($out['result']['success'], 'envoi contre un faux SMTP local : ' . $out['result']['error']);
            self::assertSame('sent', $out['result']['status']);
            self::assertNotNull($out['row'], 'la ligne write-ahead doit exister');
            self::assertSame('sent', $out['row']['status']);
            self::assertSame('<p>Corps sonde outbox</p>', $out['row']['body_html'], 'le corps HTML doit être conservé');
            self::assertSame(1, (int) $out['row']['attempts']);
            self::assertNull($out['row']['next_retry_at'], 'un envoi réussi ne planifie aucun rejeu');
        } finally {
            $this->cleanupDb($db);
        }
    }

    public function testSmtpDownPersistsErrorRowWithBodyAndRetry(): void
    {
        $db = $this->scratchDb('smtpdown');
        try {
            $out = $this->runProbe('smtp_down', $db);

            self::assertFalse($out['result']['success']);
            self::assertSame('error', $out['result']['status']);
            self::assertNotNull($out['row'], 'la ligne doit être écrite AVANT la tentative SMTP (write-ahead)');
            self::assertSame('error', $out['row']['status']);
            self::assertSame('<p>Corps sonde outbox</p>', $out['row']['body_html'], 'le corps HTML doit survivre à l\'échec SMTP');
            self::assertSame(1, (int) $out['row']['attempts']);
            self::assertNotNull($out['row']['next_retry_at'], 'un échec réessayable doit planifier un rejeu');
        } finally {
            $this->cleanupDb($db);
        }
    }

    public function testInsertFailureBlocksSendAndReturnsStructuredFailure(): void
    {
        $db = $this->scratchDb('insertfail');
        try {
            $out = $this->runProbe('insert_fail', $db);

            self::assertFalse($out['result']['success']);
            // smtp_host est vide dans ce scénario : si transmit() était atteint,
            // le statut serait 'blocked'. 'error' prouve que l'envoi a été
            // annulé AVANT tout contact SMTP.
            self::assertSame('error', $out['result']['status']);
            self::assertStringContainsString('technicien', $out['result']['error']);
            self::assertNull($out['row'], 'aucune ligne d\'outbox ne doit exister en cas d\'échec du write-ahead');
        } finally {
            $this->cleanupDb($db);
        }
    }
}