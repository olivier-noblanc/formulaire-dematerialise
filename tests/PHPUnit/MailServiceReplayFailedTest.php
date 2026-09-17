<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Enum\MailStatus;
use App\Mail\MailOutbox;
use App\Mail\MailService;
use App\Repository\MailRepository;
use App\Settings\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Lane B — rejeu MANUEL opérateur : MailService::replayFailed().
 *
 * Vérifie la chaîne service au-dessus du CAS repository :
 *  - succès : claim → transmit `sent` → ligne `sent`, bail coupé, manuel +1 ;
 *  - échec SMTP réessayable : ligne `error` + backoff (repart en file auto) ;
 *  - config SMTP absente : `blocked`, jamais réessayé automatiquement ;
 *  - refus SANS SMTP : statut ≠ `failed`, corps purgé, plafond atteint,
 *    corps vide, identifiant inconnu.
 *
 * Les échecs SMTP utilisent un port fermé (127.0.0.1:1) — connexion refusée
 * immédiatement, donc échec réessayable sans faux serveur. Le chemin `sent`
 * utilise le faux serveur SMTP local (tests/regression/_fake_smtp_server.php).
 *
 * Fichier : tests/PHPUnit/MailServiceReplayFailedTest.php
 */
final class MailServiceReplayFailedTest extends TestCase
{
    private Database $db;
    private MailRepository $repo;
    private MailService $mail;
    private SettingsService $settings;

    /** @var list<string> */
    private array $createdIds = [];

    /** @var array<string, string> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->repo = new MailRepository($this->db);
        $this->settings = \App\Core\App::settings();
        $this->mail = new MailService($this->repo, $this->settings);

        foreach (['smtp_host', 'smtp_port', 'smtp_from', 'smtp_from_name', 'mail_dry_run'] as $key) {
            $this->savedSettings[$key] = $this->settings->get($key, '');
        }
        // SMTP injoignable par défaut pour ces tests.
        $this->settings->set('smtp_host', '127.0.0.1', 'test');
        $this->settings->set('smtp_port', '1', 'test');
        $this->settings->set('smtp_from', 'noreply@test.local', 'test');
        $this->settings->set('smtp_from_name', 'CircuitDemat', 'test');
        // Envoi réel (pas de dry-run) : ces tests exercent la vraie tentative SMTP.
        $this->settings->set('mail_dry_run', '0', 'test');
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM mail_log WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
        foreach ($this->savedSettings as $key => $value) {
            $this->settings->set($key, $value, 'test');
        }
    }

    private function newId(): string
    {
        $id = 'manual-svc-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        return $id;
    }

    /**
     * @param array{status?: string, body_html?: string|null, manual_replay_count?: int, attempts?: int, recipient?: string} $overrides
     */
    private function seed(array $overrides = []): string
    {
        $id = $this->newId();
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, manual_replay_count, actor, ip)
             VALUES (?, datetime('now'), ?, 'Sujet rejeu manuel', ?, ?, 'SMTP down', '', ?, NULL, ?, 'testeur', '127.0.0.1')"
        )->execute([
            $id,
            $overrides['recipient'] ?? 'dest@test.local',
            array_key_exists('body_html', $overrides) ? $overrides['body_html'] : '<p>Corps rejeu</p>',
            $overrides['status'] ?? MailStatus::Failed->value,
            $overrides['attempts'] ?? 5,
            $overrides['manual_replay_count'] ?? 0,
        ]);
        return $id;
    }

    /**
     * @return array{status: string, attempts: int|string, next_retry_at: string|null, body_html: string|null, manual_replay_count: int|string}
     */
    private function readRow(string $id): array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT status, attempts, next_retry_at, body_html, manual_replay_count FROM mail_log WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'la ligne mail_log doit exister');
        /** @var array{status: string, attempts: int|string, next_retry_at: string|null, body_html: string|null, manual_replay_count: int|string} $row */
        return $row;
    }

    // ── Refus (ligne inchangée, aucun SMTP contacté) ────────────

    public function testReplayRefusesWhenStatusIsNotFailed(): void
    {
        $id = $this->seed(['status' => MailStatus::Sent->value]);

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Sent->value, $row['status'], 'une ligne déjà envoyée n\'est pas rejouée');
        self::assertSame(0, (int) $row['manual_replay_count'], 'pas de revendication si statut ≠ failed');
    }

    public function testReplayRefusesWhenBodyPurged(): void
    {
        $id = $this->seed(['body_html' => null]);

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $row['status'], 'refus sans SMTP, la ligne reste failed');
        self::assertSame(0, (int) $row['manual_replay_count'], 'pas de revendication si le corps est purgé');
    }

    public function testReplayRefusesWhenManualCapReached(): void
    {
        $id = $this->seed(['manual_replay_count' => MailOutbox::MANUAL_REPLAY_MAX]);

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $row['status']);
        self::assertSame(MailOutbox::MANUAL_REPLAY_MAX, (int) $row['manual_replay_count'], 'le plafond ne dépasse pas');
    }

    public function testReplayRefusesEmptyBodyWithoutSmtp(): void
    {
        $id = $this->seed(['body_html' => '']);

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $row['status'], 'corps vide → échec définitif, pas de SMTP');
        self::assertSame(1, (int) $row['manual_replay_count'], 'le claim a bien eu lieu');
    }

    public function testReplayRefusesUnknownId(): void
    {
        $result = $this->mail->replayFailed('manual-svc-inexistant-' . bin2hex(random_bytes(4)));

        self::assertFalse($result['success']);
        self::assertSame(MailStatus::Failed->value, $result['status']);
    }

    /**
     * BUG2 — mail_dry_run=1 : le rejeu manuel NE DOIT PAS contacter le SMTP ni
     * revendiquer la ligne. transmit() ignore mail_dry_run ; sans ce garde-fou,
     * un opérateur cliquerait « rejouer » en dry-run et enverrait un vrai email.
     */
    public function testReplayRefusesWhenDryRunEnabled(): void
    {
        $this->settings->set('mail_dry_run', '1', 'test');
        $id = $this->seed();
        $before = $this->readRow($id);

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        self::assertSame(MailStatus::Blocked->value, $result['status'], 'refus définitif en dry-run');
        self::assertStringContainsString('dry-run', $result['error']);
        $after = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $after['status'], 'la ligne reste failed (non revendiquée)');
        self::assertSame(0, (int) $after['manual_replay_count'], 'aucune revendication en dry-run');
        self::assertSame((int) $before['attempts'], (int) $after['attempts'], 'attempts inchangé');
        self::assertSame($before['next_retry_at'], $after['next_retry_at'], 'bail inchangé');
    }

    // ── Rejeu effectif ───────────────────────────────────────────

    public function testReplayRetryableFailureReschedulesWithBackoff(): void
    {
        $id = $this->seed();
        $before = time();

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        self::assertSame(MailStatus::Error->value, $result['status']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Error->value, $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame(1, (int) $row['manual_replay_count']);
        self::assertNotNull($row['next_retry_at'], 'un échec réessayable repart en file automatique');
        $retryTs = strtotime($row['next_retry_at'] . ' UTC');
        self::assertIsInt($retryTs);
        self::assertGreaterThanOrEqual($before + MailOutbox::BACKOFF_SECONDS - 5, $retryTs, 'backoff de 15 min minimum');
    }

    public function testReplayMarksBlockedWhenSmtpHostMissing(): void
    {
        $this->settings->set('smtp_host', '', 'test');
        $id = $this->seed();

        $result = $this->mail->replayFailed($id);

        self::assertFalse($result['success']);
        self::assertSame(MailStatus::Blocked->value, $result['status']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Blocked->value, $row['status']);
        self::assertSame(1, (int) $row['manual_replay_count']);
        self::assertNull($row['next_retry_at'], 'blocked n\'est jamais réessayé automatiquement');
    }

    public function testReplaySendsMessageAgainstLocalSmtp(): void
    {
        $root = dirname(__DIR__, 2);
        $fakeServer = $root . '/tests/regression/_fake_smtp_server.php';
        if (!is_file($fakeServer)) {
            self::markTestSkipped('faux serveur SMTP introuvable');
        }

        // Trouve un port libre puis le relâche avant de servir dessus.
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::fail('impossible de trouver un port libre : ' . $errstr);
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $marker = sys_get_temp_dir() . '/fake_smtp_replay_failed_' . getmypid() . '_' . uniqid() . '.ready';
        @unlink($marker);
        $cmd = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($fakeServer) . ' '
            . escapeshellarg((string) $port) . ' '
            . escapeshellarg($marker) . ' 1';
        $server = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $serverPipes,
            $root
        );
        self::assertIsResource($server, 'proc_open() du faux serveur SMTP a échoué');
        fclose($serverPipes[0]);

        try {
            $deadline = microtime(true) + 5.0;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(50000);
            }
            self::assertFileExists($marker, 'faux serveur SMTP non prêt');

            $this->settings->set('smtp_host', '127.0.0.1', 'test');
            $this->settings->set('smtp_port', (string) $port, 'test');

            $id = $this->seed();

            $result = $this->mail->replayFailed($id);

            self::assertTrue($result['success']);
            self::assertSame(MailStatus::Sent->value, $result['status']);
            $row = $this->readRow($id);
            self::assertSame(MailStatus::Sent->value, $row['status']);
            self::assertSame(1, (int) $row['attempts']);
            self::assertSame(1, (int) $row['manual_replay_count']);
            self::assertNull($row['next_retry_at'], 'un succès coupe le rejeu');
        } finally {
            foreach ([1, 2] as $i) {
                if (isset($serverPipes[$i]) && is_resource($serverPipes[$i])) {
                    stream_set_blocking($serverPipes[$i], false);
                    stream_get_contents($serverPipes[$i]);
                    fclose($serverPipes[$i]);
                }
            }
            @proc_terminate($server);
            @proc_close($server);
            @unlink($marker);
        }
    }
}