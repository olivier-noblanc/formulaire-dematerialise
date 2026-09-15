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

    private function seedMail(string $recipient, string $createdAt, ?string $body = '<p>Données personnelles</p>'): string
    {
        $id = 'rgpd-mail-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, actor, ip)
             VALUES (?, ?, ?, 'Sujet RGPD', ?, ?, '', '', 1, NULL, 'system', 'CLI')"
        )->execute([$id, $createdAt, $recipient, $body, MailStatus::Sent->value]);
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