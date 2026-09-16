<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Enum\MailStatus;
use App\Repository\MailRepository;
use App\Rgpd\RgpdService;
use PHPUnit\Framework\TestCase;

/**
 * A5 — RGPD minimal sur l'outbox : export (métadonnées), anonymisation lors de
 * l'effacement, purge des emails anciens.
 *
 * Fichier : tests/PHPUnit/RgpdMailLogTest.php
 */
final class RgpdMailLogTest extends TestCase
{
    private Database $db;
    private RgpdService $service;
    private string $originalUser;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->service = new RgpdService();
        $this->originalUser = $_SERVER['HTTP_X_TEST_USER'] ?? '';
        $this->createdIds = [];
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_X_TEST_USER'] = $this->originalUser;
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM mail_log WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    private function seedMail(string $recipient, string $createdAt, ?string $body = '<p>Données personnelles</p>', string $status = MailStatus::Sent->value): string
    {
        $id = 'rgpd-mail-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, actor, ip)
             VALUES (?, ?, ?, 'Sujet RGPD', ?, ?, '', '', 1, NULL, 'system', 'CLI')"
        )->execute([$id, $createdAt, $recipient, $body, $status]);
        return $id;
    }

    /** @return array{recipient: mixed, body_html: mixed} */
    private function readMail(string $id): array
    {
        $stmt = $this->db->getPdo()->prepare('SELECT recipient, body_html FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        /** @var array{recipient: mixed, body_html: mixed} $row */
        return $row;
    }

    private function bodyOf(string $id): string|null|false
    {
        $stmt = $this->db->getPdo()->prepare('SELECT body_html FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn();
    }

    private function mailExists(string $id): bool
    {
        $stmt = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM mail_log WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function testDeleteUserDataAnonymizesRecipientAndPurgesBody(): void
    {
        $email = 'rgpd-outbox-' . uniqid() . '@test.local';
        $_SERVER['HTTP_X_TEST_USER'] = $email;
        $id = $this->seedMail($email, gmdate('Y-m-d H:i:s'));

        $ok = $this->service->deleteUserData($email);

        self::assertTrue($ok);
        $row = $this->readMail($id);
        self::assertSame('[supprimé]', $row['recipient']);
        self::assertNull($row['body_html'], 'le corps HTML doit être purgé');
    }

    public function testExportUserDataIncludesEmailMetadataWithoutBody(): void
    {
        $email = 'rgpd-export-' . uniqid() . '@test.local';
        $_SERVER['HTTP_X_TEST_USER'] = $email;
        $this->seedMail($email, gmdate('Y-m-d H:i:s'));

        $result = $this->service->exportUserData($email);

        self::assertArrayHasKey('emails', $result);
        self::assertNotEmpty($result['emails']);
        $entry = $result['emails'][0];
        self::assertArrayHasKey('subject', $entry);
        self::assertArrayHasKey('status', $entry);
        self::assertArrayNotHasKey('body_html', $entry, 'le corps ne doit pas être exporté');
        self::assertSame('Sujet RGPD', $entry['subject']);
    }

    public function testAutoPurgeDeletesOldEmailsButKeepsRecent(): void
    {
        $old = $this->seedMail('old-purge@test.local', gmdate('Y-m-d H:i:s', strtotime('-25 months')));
        $recent = $this->seedMail('recent-purge@test.local', gmdate('Y-m-d H:i:s'));

        $this->service->autoPurge(24);

        $pdo = $this->db->getPdo();
        $check = $pdo->prepare('SELECT COUNT(*) FROM mail_log WHERE id = ?');
        $check->execute([$old]);
        self::assertSame(0, (int) $check->fetchColumn(), 'un email de plus de 24 mois doit être purgé');
        $check->execute([$recent]);
        self::assertSame(1, (int) $check->fetchColumn(), 'un email récent doit être conservé');
    }

    public function testAutoPurgePurgesOldBodiesWithRetentionWindowsAndAuditsCount(): void
    {
        $sentOld = $this->seedMail('body-sent-old@test.local', gmdate('Y-m-d H:i:s', time() - 8 * 86400));
        $sentFresh = $this->seedMail('body-sent-fresh@test.local', gmdate('Y-m-d H:i:s', time() - 86400));
        $failedWithin = $this->seedMail('body-failed-within@test.local', gmdate('Y-m-d H:i:s', time() - 25 * 86400), '<p>Données personnelles</p>', MailStatus::Failed->value);
        $failedOld = $this->seedMail('body-failed-old@test.local', gmdate('Y-m-d H:i:s', time() - 31 * 86400), '<p>Données personnelles</p>', MailStatus::Failed->value);
        $pendingOld = $this->seedMail('body-pending-old@test.local', gmdate('Y-m-d H:i:s', time() - 100 * 86400), '<p>Données personnelles</p>', MailStatus::Pending->value);

        $this->service->autoPurge(24);

        self::assertNull($this->bodyOf($sentOld), 'un succès de plus de 7 jours est purgé');
        self::assertSame('<p>Données personnelles</p>', $this->bodyOf($sentFresh), 'un succès récent est conservé');
        self::assertSame('<p>Données personnelles</p>', $this->bodyOf($failedWithin), 'un échec terminal de moins de 30 jours est conservé');
        self::assertNull($this->bodyOf($failedOld), 'un échec terminal de plus de 30 jours est purgé');
        self::assertSame('<p>Données personnelles</p>', $this->bodyOf($pendingOld), 'un pending n\'est jamais purgé');

        // Les lignes restent présentes (seul le corps est purgé).
        foreach ([$sentOld, $sentFresh, $failedWithin, $failedOld, $pendingOld] as $id) {
            self::assertTrue($this->mailExists($id), 'la ligne mail_log doit être conservée');
        }

        // Audit : le compteur de corps purgés est journalisé.
        $stmt = $this->db->getPdo()->query("SELECT detail FROM audit_log WHERE action = 'rgpd_purge' ORDER BY rowid DESC LIMIT 1");
        self::assertNotFalse($stmt);
        $detail = (string) $stmt->fetchColumn();
        self::assertStringContainsString("corps d'emails purgés", $detail);
    }

    public function testFindByRecipientReturnsMetadataWithoutBody(): void
    {
        $email = 'rgpd-repo-' . uniqid() . '@test.local';
        $this->seedMail($email, gmdate('Y-m-d H:i:s'));
        $repo = new MailRepository($this->db);

        $rows = $repo->findByRecipient($email);

        self::assertNotEmpty($rows);
        self::assertArrayNotHasKey('body_html', $rows[0]);
        self::assertSame('Sujet RGPD', $rows[0]['subject']);
    }
}