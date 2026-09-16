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
 * A3 — worker de rejeu de l'outbox : revendication atomique, backoff 15 min,
 * échec définitif après MAX_ATTEMPTS tentatives.
 *
 * Les tests d'échec SMTP utilisent un port fermé (127.0.0.1:1) : la connexion
 * est refusée immédiatement, ce qui produit un échec réessayable sans dépendre
 * d'un faux serveur. Le chemin `sent` est couvert par la sonde
 * tests/regression/_mail_replay_probe.php (faux SMTP local).
 *
 * Fichier : tests/PHPUnit/MailOutboxReplayTest.php
 */
final class MailOutboxReplayTest extends TestCase
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

        foreach (['smtp_host', 'smtp_port', 'smtp_from', 'smtp_from_name'] as $key) {
            $this->savedSettings[$key] = $this->settings->get($key, '');
        }
        // SMTP injoignable par défaut pour ces tests.
        $this->settings->set('smtp_host', '127.0.0.1', 'test');
        $this->settings->set('smtp_port', '1', 'test');
        $this->settings->set('smtp_from', 'noreply@test.local', 'test');
        $this->settings->set('smtp_from_name', 'CircuitDemat', 'test');
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
        $id = 'replay-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        return $id;
    }

    /**
     * @param array{status?: string, attempts?: int, next_retry_at?: string|null, body_html?: string|null, created_at?: string, recipient?: string} $overrides
     */
    private function seed(array $overrides = []): string
    {
        $id = $this->newId();
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, actor, ip)
             VALUES (?, ?, ?, 'Sujet rejeu', ?, ?, 'SMTP down', '', ?, ?, 'testeur', '127.0.0.1')"
        )->execute([
            $id,
            $overrides['created_at'] ?? gmdate('Y-m-d H:i:s'),
            $overrides['recipient'] ?? 'dest@test.local',
            array_key_exists('body_html', $overrides) ? $overrides['body_html'] : '<p>Corps rejeu</p>',
            $overrides['status'] ?? MailStatus::Error->value,
            $overrides['attempts'] ?? 1,
            array_key_exists('next_retry_at', $overrides) ? $overrides['next_retry_at'] : gmdate('Y-m-d H:i:s', time() - 60),
        ]);
        return $id;
    }

    /** @return array{status: string, attempts: int|string, next_retry_at: string|null, body_html: string|null} */
    private function readRow(string $id): array
    {
        $stmt = $this->db->getPdo()->prepare('SELECT status, attempts, next_retry_at, body_html FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        /** @var array{status: string, attempts: int|string, next_retry_at: string|null, body_html: string|null} $row */
        return $row;
    }

    // ── claimRetryable (revendication atomique) ──────────────────

    public function testClaimRetryableMarksLineAsLeasedAndIncrementsAttempts(): void
    {
        $id = $this->seed(['attempts' => 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + MailOutbox::LEASE_SECONDS);

        $claimed = $this->repo->claimRetryable(MailOutbox::MAX_ATTEMPTS, 20, $leaseUntil, MailOutbox::STALE_PENDING_SECONDS);

        $ids = array_column($claimed, 'id');
        self::assertContains($id, $ids);
        $row = $this->readRow($id);
        self::assertSame(2, (int) $row['attempts'], 'attempts doit être incrémenté à la revendication');
        self::assertSame($leaseUntil, $row['next_retry_at'], 'le bail verrouille la ligne pendant l\'envoi');
    }

    public function testClaimRetryableSkipsLineScheduledInFuture(): void
    {
        $id = $this->seed(['next_retry_at' => gmdate('Y-m-d H:i:s', time() + 900)]);

        $claimed = $this->repo->claimRetryable(MailOutbox::MAX_ATTEMPTS, 20, gmdate('Y-m-d H:i:s', time() + 900), MailOutbox::STALE_PENDING_SECONDS);

        self::assertNotContains($id, array_column($claimed, 'id'), 'le backoff doit être respecté');
        self::assertSame(1, (int) $this->readRow($id)['attempts']);
    }

    public function testClaimRetryableSkipsLineAtMaxAttempts(): void
    {
        $id = $this->seed(['attempts' => MailOutbox::MAX_ATTEMPTS, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $claimed = $this->repo->claimRetryable(MailOutbox::MAX_ATTEMPTS, 20, gmdate('Y-m-d H:i:s', time() + 900), MailOutbox::STALE_PENDING_SECONDS);

        self::assertNotContains($id, array_column($claimed, 'id'), 'une ligne épuisée ne doit plus être revendiquée');
    }

    public function testClaimRetryableReapsStalePendingButNotFreshPending(): void
    {
        $stale = $this->seed([
            'status' => MailStatus::Pending->value,
            'attempts' => 0,
            'next_retry_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s', time() - MailOutbox::STALE_PENDING_SECONDS - 60),
        ]);
        $fresh = $this->seed([
            'status' => MailStatus::Pending->value,
            'attempts' => 0,
            'next_retry_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $claimed = $this->repo->claimRetryable(MailOutbox::MAX_ATTEMPTS, 20, gmdate('Y-m-d H:i:s', time() + 900), MailOutbox::STALE_PENDING_SECONDS);
        $ids = array_column($claimed, 'id');

        self::assertContains($stale, $ids, 'un pending orphelin doit être repris');
        self::assertNotContains($fresh, $ids, 'un pending récent ne doit pas être doublé');
    }

    public function testClaimRetryableSkipsStalePendingUnderLease(): void
    {
        // F2 : pending orphelin MAIS sous bail (next_retry_at dans le futur) —
        // déjà revendiqué par un worker vivant → ne doit PAS être reclaimé.
        $id = $this->seed([
            'status' => MailStatus::Pending->value,
            'attempts' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', time() - MailOutbox::STALE_PENDING_SECONDS - 60),
            'next_retry_at' => gmdate('Y-m-d H:i:s', time() + MailOutbox::LEASE_SECONDS),
        ]);

        $claimed = $this->repo->claimRetryable(MailOutbox::MAX_ATTEMPTS, 20, gmdate('Y-m-d H:i:s', time() + 900), MailOutbox::STALE_PENDING_SECONDS);

        self::assertNotContains($id, array_column($claimed, 'id'), 'un pending sous bail ne doit pas être repris');
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Pending->value, $row['status'], 'la revendication d\'un pending ne change pas son statut');
        self::assertSame(0, (int) $row['attempts'], 'un pending non repris ne voit pas ses attempts incrémentés');
    }

    public function testClaimRetryableReclaimsStalePendingAfterLeaseExpires(): void
    {
        // F2 : pending orphelin dont le bail est ÉCHU (next_retry_at dans le
        // passé) → reclaimable, et le statut doit rester `pending`.
        $id = $this->seed([
            'status' => MailStatus::Pending->value,
            'attempts' => 0,
            'created_at' => gmdate('Y-m-d H:i:s', time() - MailOutbox::STALE_PENDING_SECONDS - 60),
            'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $claimed = $this->repo->claimRetryable(MailOutbox::MAX_ATTEMPTS, 20, gmdate('Y-m-d H:i:s', time() + 900), MailOutbox::STALE_PENDING_SECONDS);

        self::assertContains($id, array_column($claimed, 'id'), 'un pending dont le bail est échu doit être repris');
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Pending->value, $row['status'], 'la revendication ne change pas le statut pending');
        self::assertSame(1, (int) $row['attempts'], 'attempts incrémenté à la revendication');
    }

    public function testCountByStatusReturnsExactCount(): void
    {
        $this->seed(['status' => MailStatus::Failed->value, 'attempts' => 5]);
        $this->seed(['status' => MailStatus::Failed->value, 'attempts' => 5]);
        $this->seed(['status' => MailStatus::Error->value]);

        self::assertGreaterThanOrEqual(2, $this->repo->countByStatus(MailStatus::Failed));
    }

    // ── replayOutbox (worker) ───────────────────────────────────

    public function testReplayOutboxRetryableFailureReschedulesWithBackoff(): void
    {
        $id = $this->seed(['attempts' => 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $before = time();

        $stats = $this->mail->replayOutbox();

        self::assertGreaterThanOrEqual(1, $stats['processed']);
        self::assertGreaterThanOrEqual(1, $stats['error']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Error->value, $row['status']);
        self::assertSame(2, (int) $row['attempts']);
        self::assertNotNull($row['next_retry_at']);
        $retryTs = strtotime($row['next_retry_at'] . ' UTC');
        self::assertIsInt($retryTs);
        self::assertGreaterThanOrEqual($before + MailOutbox::BACKOFF_SECONDS - 5, $retryTs, 'backoff de 15 min minimum');
    }

    public function testReplayOutboxMarksFailedAfterMaxAttempts(): void
    {
        $id = $this->seed(['attempts' => MailOutbox::MAX_ATTEMPTS - 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $stats = $this->mail->replayOutbox();

        self::assertGreaterThanOrEqual(1, $stats['failed']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $row['status'], 'échec définitif après 5 tentatives');
        self::assertSame(MailOutbox::MAX_ATTEMPTS, (int) $row['attempts']);
        self::assertNull($row['next_retry_at'], 'plus de rejeu automatique après failed');
    }

    public function testReplayOutboxMarksFailedWhenBodyPurged(): void
    {
        $id = $this->seed(['body_html' => null, 'attempts' => 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $stats = $this->mail->replayOutbox();

        self::assertGreaterThanOrEqual(1, $stats['failed']);
        $row = $this->readRow($id);
        self::assertSame(MailStatus::Failed->value, $row['status']);
        self::assertNull($row['next_retry_at']);
    }

    public function testReplayOutboxRespectsBackoffAndProcessesNothingWhenNotDue(): void
    {
        $id = $this->seed(['attempts' => 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() + 900)]);

        $stats = $this->mail->replayOutbox();

        self::assertSame(0, $stats['processed'], 'aucune ligne due → rien à rejouer');
        self::assertSame(1, (int) $this->readRow($id)['attempts']);
        self::assertSame(MailStatus::Error->value, $this->readRow($id)['status']);
    }

    public function testReplayOutboxOnEmptyOutboxReturnsZeroStats(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->prepare("DELETE FROM mail_log WHERE recipient = 'dest@test.local'")->execute();

        $stats = $this->mail->replayOutbox();

        self::assertSame(['processed' => 0, 'sent' => 0, 'failed' => 0, 'error' => 0, 'blocked' => 0], $stats);
    }

    public function testReplayOutboxSendsMessageAgainstLocalSmtp(): void
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

        $marker = sys_get_temp_dir() . '/fake_smtp_replay_' . getmypid() . '_' . uniqid() . '.ready';
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

            $id = $this->seed(['attempts' => 1, 'next_retry_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

            $stats = $this->mail->replayOutbox();

            self::assertSame(1, $stats['sent']);
            $row = $this->readRow($id);
            self::assertSame(MailStatus::Sent->value, $row['status']);
            self::assertSame(2, (int) $row['attempts']);
            self::assertNull($row['next_retry_at']);
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