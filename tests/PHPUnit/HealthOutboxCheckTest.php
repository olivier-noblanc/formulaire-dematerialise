<?php
declare(strict_types=1);

namespace App\Tests;

use App\Controller\HealthController;
use App\Core\Database;
use App\Enum\MailStatus;
use PHPUnit\Framework\TestCase;

/**
 * A4 — le health check doit passer en 503 si l'outbox contient des emails en
 * échec définitif, SANS fuite de détails (pas de destinataire, pas de message
 * d'erreur SMTP brut) — l'endpoint est public.
 *
 * Fichier : tests/PHPUnit/HealthOutboxCheckTest.php
 */
final class HealthOutboxCheckTest extends TestCase
{
    private Database $db;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->createdIds = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        foreach ($this->createdIds as $id) {
            $pdo->prepare('DELETE FROM mail_log WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
    }

    private function seedFailedMail(): string
    {
        $id = 'health-failed-' . bin2hex(random_bytes(8));
        $this->createdIds[] = $id;
        $this->db->getPdo()->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, actor, ip)
             VALUES (?, datetime('now'), ?, ?, '<p>secret</p>', ?, ?, 'CONNEXION SMTP REFUSEE', 5, NULL, 'system', '127.0.0.1')"
        )->execute([
            $id,
            'destinataire-secret@example.invalid',
            'Sujet secret',
            MailStatus::Failed->value,
            'Erreur SMTP interne detaillee',
        ]);
        return $id;
    }

    /** @return array{label: string, ok: bool, detail: string}|null */
    private function findCheck(array $checks, string $labelPart): ?array
    {
        foreach ($checks as $check) {
            if (str_contains($check['label'], $labelPart)) {
                return $check;
            }
        }
        return null;
    }

    public function testOutboxFailureTriggersUnhealthy503(): void
    {
        $this->seedFailedMail();

        $result = new HealthController()->evaluate();

        $check = $this->findCheck($result['checks'], 'emails');
        self::assertNotNull($check, 'le contrôle outbox doit exister');
        self::assertFalse($check['ok'], 'un échec définitif doit rendre le contrôle KO');
        self::assertSame(503, $result['http_status']);
        self::assertFalse($result['healthy']);
    }

    public function testOutboxFailureDetailDoesNotLeakRecipientOrError(): void
    {
        $this->seedFailedMail();

        $result = new HealthController()->evaluate();
        $check = $this->findCheck($result['checks'], 'emails');
        self::assertNotNull($check);

        // Le détail est public : ni destinataire, ni sujet, ni erreur SMTP brute.
        self::assertStringNotContainsString('destinataire-secret@example.invalid', $check['detail']);
        self::assertStringNotContainsString('Sujet secret', $check['detail']);
        self::assertStringNotContainsString('Erreur SMTP interne detaillee', $check['detail']);
        self::assertStringNotContainsString('CONNEXION SMTP REFUSEE', $check['detail']);
    }

    public function testOutboxHealthyWhenNoDefinitiveFailure(): void
    {
        $baseline = new \App\Repository\MailRepository($this->db)->countByStatus(MailStatus::Failed);

        $result = new HealthController()->evaluate();

        $check = $this->findCheck($result['checks'], 'emails');
        self::assertNotNull($check);
        // Cohérence : le contrôle reflète exactement l'état de l'outbox.
        self::assertSame($baseline === 0, $check['ok']);
    }

    public function testHealthDetailsDoNotLeakExceptionMessages(): void
    {
        $result = new HealthController()->evaluate();

        // Aucun détail ne doit contenir de chemin Windows / message technique.
        foreach ($result['checks'] as $check) {
            self::assertStringNotContainsString('\\', $check['detail'], 'detail health ne doit pas exposer de chemin');
        }
    }
}