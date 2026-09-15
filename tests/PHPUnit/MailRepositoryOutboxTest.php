<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Enum\MailStatus;
use App\Repository\MailRepository;
use PHPUnit\Framework\TestCase;

/**
 * A2 — cycle de l'outbox write-ahead au niveau MailRepository.
 *
 * Vérifie que le corps HTML est bien persisté dès l'insertion `pending`, puis
 * conservé par la finalisation `sent` / `error` (avec planification de rejeu).
 * Couvre aussi le round-trip de la migration v38 : `pending` est accepté par le
 * CHECK élargi et les colonnes body_html/attempts/next_retry_at existent.
 *
 * Fichier : tests/PHPUnit/MailRepositoryOutboxTest.php
 */
final class MailRepositoryOutboxTest extends TestCase
{
    private Database $db;
    private MailRepository $repo;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->repo = new MailRepository($this->db);
        $this->createdIds = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM mail_log WHERE id = ?')->execute([$id]);
        }
    }

    private function newId(): string
    {
        $id = 'outbox-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        return $id;
    }

    /** @return array{status: string, body_html: string|null, attempts: int|string, next_retry_at: string|null} */
    private function readRow(string $id): array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT status, body_html, attempts, next_retry_at FROM mail_log WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'la ligne mail_log doit exister');
        /** @var array{status: string, body_html: string|null, attempts: int|string, next_retry_at: string|null} $row */
        return $row;
    }

    public function testInsertPendingPersistsBodyAndPendingStatus(): void
    {
        $id = $this->newId();

        self::assertTrue($this->repo->insertPending($id, 'dest@test.local', 'Sujet', '<p>Corps HTML</p>', 'testeur', '127.0.0.1'));

        $row = $this->readRow($id);
        self::assertSame(MailStatus::Pending->value, $row['status']);
        self::assertSame('<p>Corps HTML</p>', $row['body_html']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['next_retry_at']);
    }

    public function testFinalizeToSentKeepsBodyAndClearsRetry(): void
    {
        $id = $this->newId();
        self::assertTrue($this->repo->insertPending($id, 'dest@test.local', 'Sujet', '<p>Corps</p>', 'testeur', 'IP'));

        self::assertTrue($this->repo->finalize($id, MailStatus::Sent, '', 'SMTP ok', 1, null));

        $row = $this->readRow($id);
        self::assertSame(MailStatus::Sent->value, $row['status']);
        self::assertSame('<p>Corps</p>', $row['body_html']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNull($row['next_retry_at']);
    }

    public function testFinalizeToErrorSchedulesRetryAndKeepsBody(): void
    {
        $id = $this->newId();
        self::assertTrue($this->repo->insertPending($id, 'dest@test.local', 'Sujet', '<p>Corps</p>', 'testeur', 'IP'));

        $retryAt = gmdate('Y-m-d H:i:s', time() + 900);
        self::assertTrue($this->repo->finalize($id, MailStatus::Error, 'SMTP down', '[3] connect failed', 1, $retryAt));

        $row = $this->readRow($id);
        self::assertSame(MailStatus::Error->value, $row['status']);
        self::assertSame('<p>Corps</p>', $row['body_html']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame($retryAt, $row['next_retry_at']);
    }

    public function testFinalizeToBlockedKeepsBodyAndNoRetry(): void
    {
        $id = $this->newId();
        self::assertTrue($this->repo->insertPending($id, 'dest@test.local', 'Sujet', '<p>Corps</p>', 'testeur', 'IP'));

        self::assertTrue($this->repo->finalize($id, MailStatus::Blocked, 'Aucun hôte SMTP configuré', '', 1, null));

        $row = $this->readRow($id);
        self::assertSame(MailStatus::Blocked->value, $row['status']);
        self::assertSame('<p>Corps</p>', $row['body_html']);
        self::assertNull($row['next_retry_at']);
    }
}