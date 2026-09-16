<?php
declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MonitoringController;
use App\Core\App;
use App\Core\Database;
use App\Enum\MailStatus;
use App\Settings\SettingsService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_controller_overrides.php';

/**
 * Lane C — rejeu manuel d'un email en échec définitif depuis la page Surveillance.
 *
 * Contrat : POST admin-only (requireAdminEffective + requireCsrf), identifiant
 * validé en UUID, un seul message rejoué (jamais de rejeu en masse), audit
 * `mail_replay` (succès) / `mail_replay_denied` (refus ou échec) et notice de
 * retour. Le corps du message n'est jamais lu ni exposé.
 *
 * Stratégie : le vrai MailService est utilisé (classe `final readonly`, non
 * substituable) — SMTP injoignable par défaut (port fermé) pour les branches
 * d'échec, faux serveur SMTP local pour la branche de succès.
 */
final class MonitoringControllerTest extends TestCase
{
    private Database $db;
    private SettingsService $settings;

    /** @var list<string> */
    private array $createdMailIds = [];

    /** @var array<string, string> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $this->settings = App::settings();

        foreach (['smtp_host', 'smtp_port', 'smtp_from', 'smtp_from_name', 'mail_dry_run'] as $key) {
            $this->savedSettings[$key] = $this->settings->get($key, '');
        }
        // SMTP injoignable par défaut (port fermé) → branche d'échec réessayable.
        $this->settings->set('smtp_host', '127.0.0.1', 'test');
        $this->settings->set('smtp_port', '1', 'test');
        $this->settings->set('smtp_from', 'noreply@test.local', 'test');
        $this->settings->set('smtp_from_name', 'CircuitDemat', 'test');
        $this->settings->set('mail_dry_run', '0', 'test');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_TEST_MODE'] = '1';
        $_SERVER['HTTP_X_TEST_USER'] = 'testeur@e2e.test';
        $_SERVER['AUTH_USER'] = 'DREETS\testeur';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/?p=monitoring';
        $_GET = [];
        $_POST = [];
        $GLOBALS['_test_captured_json'] = null;
        $GLOBALS['_test_mails'] = [];

        $this->addAdmin('testeur@e2e.test');
        $this->db->getPdo()->exec("DELETE FROM audit_log WHERE action IN ('mail_replay', 'mail_replay_denied')");
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdMailIds as $id) {
            $pdo->prepare('DELETE FROM mail_log WHERE id = ?')->execute([$id]);
        }
        $this->createdMailIds = [];
        $pdo->exec("DELETE FROM audit_log WHERE action IN ('mail_replay', 'mail_replay_denied')");
        // Restaure l'admin seedé par phpunit_bootstrap (un test le retire) —
        // le supprimer contaminerait les autres classes de tests.
        $this->addAdmin('testeur@e2e.test');
        foreach ($this->savedSettings as $key => $value) {
            $this->settings->set($key, $value, 'test');
        }
        $GLOBALS['_test_captured_json'] = null;
        $GLOBALS['_test_mails'] = [];
    }

    // ── GET : bouton + labels ───────────────────────────────────

    public function testGetShowsReplayButtonOnlyOnFailedRowAndNeverExposesBody(): void
    {
        $failedId = $this->seedMail(MailStatus::Failed->value, '<p>CORPS_SECRET_REJEU</p>');
        $pendingId = $this->seedMail(MailStatus::Pending->value, '<p>CORPS_SECRET_REJEU</p>');

        $output = $this->captureOutput(fn() => new MonitoringController()->handle());

        // Libellés explicites pending / failed (source unique : enum MailStatus).
        self::assertStringContainsString('En attente', $output);
        self::assertStringContainsString('Échec définitif', $output);

        // Un seul formulaire de rejeu (celui de la ligne failed) : pas de rejeu en masse.
        self::assertSame(1, substr_count($output, 'name="action" value="mail_replay"'), 'un seul bouton de rejeu attendu');
        self::assertStringContainsString('name="mail_log_id" value="' . $failedId . '"', $output);
        self::assertStringNotContainsString('name="mail_log_id" value="' . $pendingId . '"', $output, 'une ligne pending n\'est pas rejouable');
        self::assertStringContainsString('Rejouer l\'envoi', $output);

        // Aucun corps d'email n'est exposé dans la page.
        self::assertStringNotContainsString('CORPS_SECRET_REJEU', $output);
    }

    public function testGetWithoutAdminIsDenied(): void
    {
        $this->removeAdmin('testeur@e2e.test');

        $this->captureOutput(fn() => new MonitoringController()->handle());

        self::assertNotNull($GLOBALS['_test_captured_json'], 'requireAdminEffective doit refuser l\'accès');
        self::assertSame('Accès refusé', $GLOBALS['_test_captured_json']['error']);
    }

    // ── POST : refus ────────────────────────────────────────────

    public function testPostReplayWithInvalidUuidIsDeniedAndAudited(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'mail_replay', 'csrf_token' => 'test', 'mail_log_id' => 'pas-un-uuid'];

        $output = $this->captureOutput(fn() => new MonitoringController()->handle());

        self::assertStringContainsString('identifiant de message invalide', $output);
        self::assertSame(1, $this->countAudit('mail_replay_denied'));
        self::assertSame(0, $this->countAudit('mail_replay'));
    }

    public function testPostReplayOnNonFailedRowIsDeniedAndRowUntouched(): void
    {
        $sentId = $this->seedMail(MailStatus::Sent->value);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'mail_replay', 'csrf_token' => 'test', 'mail_log_id' => $sentId];

        $output = $this->captureOutput(fn() => new MonitoringController()->handle());

        self::assertStringContainsString('Rejeu refusé', $output);
        self::assertSame(MailStatus::Sent->value, $this->rowStatus($sentId), 'une ligne envoyée n\'est pas rejouée');
        self::assertSame(1, $this->countAudit('mail_replay_denied'));
        self::assertSame(0, $this->countAudit('mail_replay'));
    }

    public function testPostReplayWithUnknownButValidUuidIsDeniedAndAudited(): void
    {
        $unknownId = \generate_uuid();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'mail_replay', 'csrf_token' => 'test', 'mail_log_id' => $unknownId];

        $output = $this->captureOutput(fn() => new MonitoringController()->handle());

        self::assertStringContainsString('Rejeu refusé', $output);
        self::assertSame(1, $this->countAudit('mail_replay_denied'));
        self::assertSame(0, $this->countAudit('mail_replay'));
    }

    // ── POST : succès ───────────────────────────────────────────

    public function testPostReplaySuccessMarksSentAuditsAndShowsNotice(): void
    {
        $root = dirname(__DIR__, 3);
        $fakeServer = $root . '/tests/regression/_fake_smtp_server.php';
        if (!is_file($fakeServer)) {
            self::markTestSkipped('faux serveur SMTP introuvable');
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::fail('impossible de trouver un port libre : ' . $errstr);
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $marker = sys_get_temp_dir() . '/fake_smtp_monitoring_' . getmypid() . '_' . uniqid() . '.ready';
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

            $failedId = $this->seedMail(MailStatus::Failed->value, '<p>CORPS_SECRET_REJEU</p>');

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action' => 'mail_replay', 'csrf_token' => 'test', 'mail_log_id' => $failedId];

            $output = $this->captureOutput(fn() => new MonitoringController()->handle());

            self::assertStringContainsString('envoyé avec succès', $output);
            self::assertSame(MailStatus::Sent->value, $this->rowStatus($failedId));
            self::assertSame(1, $this->countAudit('mail_replay'));
            self::assertSame(0, $this->countAudit('mail_replay_denied'));
            // NB : le corps n'est jamais rendu depuis body_html ; le journal SMTP
            // de diagnostic (SMTPDebug=3) peut légitimement contenir la trace DATA.
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

    // ─ Helpers ─────────────────────────────────────────────────

    /**
     * Insère une ligne mail_log avec un id UUID et un created_at futur (pour
     * figurer en tête du journal des 20 derniers lors du rendu).
     */
    private function seedMail(string $status, string $bodyHtml = '<p>Corps</p>'): string
    {
        $id = \generate_uuid();
        $this->createdMailIds[] = $id;
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, manual_replay_count, actor, ip)
             VALUES (?, '2999-01-01 00:00:00', 'dest@test.local', 'Sujet rejeu monitoring', ?, ?, 'SMTP down', '', 5, NULL, 0, 'testeur', '127.0.0.1')"
        )->execute([$id, $bodyHtml, $status]);
        return $id;
    }

    private function rowStatus(string $id): string
    {
        $stmt = $this->db->getPdo()->prepare('SELECT status FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    private function countAudit(string $action): int
    {
        $stmt = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $stmt->execute([$action]);
        return (int) $stmt->fetchColumn();
    }

    private function addAdmin(string $email): void
    {
        $stmt = $this->db->getPdo()->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))");
        $stmt->execute([\generate_uuid(), $email]);
    }

    private function removeAdmin(string $email): void
    {
        $this->db->getPdo()->prepare('DELETE FROM admins WHERE email = ?')->execute([$email]);
    }

    private function captureOutput(callable $callable): string
    {
        ob_start();
        try {
            $callable();
        } catch (TestJsonCapturedException) {
            // JSON capturé — on continue
        } finally {
            $output = ob_get_clean();
        }
        return (string) $output;
    }
}