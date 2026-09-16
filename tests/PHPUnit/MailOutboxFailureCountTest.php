<?php
declare(strict_types=1);

namespace App\Tests;

use App\Core\Database;
use App\Enum\MailStatus;
use App\Mail\MailService;
use App\Repository\MailRepository;
use PHPUnit\Framework\TestCase;

/**
 * F6 — MailService::getOutboxFailureCount() : distinction stricte entre
 * « 0 échec définitif » (sain) et « compteur illisible » (null → état inconnu).
 *
 * Un compteur illisible ne doit JAMAIS être rapporté comme 0 (faux sain) : le
 * health check bascule alors en 503 et la surveillance affiche « état inconnu ».
 *
 * Fichier : tests/PHPUnit/MailOutboxFailureCountTest.php
 */
final class MailOutboxFailureCountTest extends TestCase
{
    private ?string $savedTestDbPath = null;
    private ?string $dbPath = null;
    private ?Database $db = null;

    protected function tearDown(): void
    {
        $this->db?->release();
        if ($this->dbPath !== null) {
            foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        if ($this->savedTestDbPath === null) {
            unset($GLOBALS['_test_db_path']);
        } else {
            $GLOBALS['_test_db_path'] = $this->savedTestDbPath;
        }
    }

    /**
     * Ouvre une base SQLite isolée (schéma complet migré) pour un décompte exact.
     */
    private function isolatedDb(): Database
    {
        $this->savedTestDbPath = $GLOBALS['_test_db_path'] ?? null;
        $path = tempnam(sys_get_temp_dir(), 'outboxcount_');
        self::assertNotFalse($path);
        $this->dbPath = $path;
        $GLOBALS['_test_db_path'] = $path;
        $this->db = new Database();
        self::assertInstanceOf(\PDO::class, $this->db->getPdo());
        return $this->db;
    }

    private function serviceFor(Database $db): MailService
    {
        return new MailService(new MailRepository($db), \App\Core\App::settings());
    }

    public function testReturnsZeroWhenNoDefinitiveFailure(): void
    {
        $db = $this->isolatedDb();

        self::assertSame(0, $this->serviceFor($db)->getOutboxFailureCount(), '0 = aucun échec définitif');
    }

    public function testReturnsExactCountOfFailedRows(): void
    {
        $db = $this->isolatedDb();
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, manual_replay_count, actor, ip)
             VALUES (?, datetime('now'), 'dest@test.local', 'Sujet', '<p>x</p>', ?, 'err', '', 5, NULL, 0, 'testeur', '127.0.0.1')"
        );
        $stmt->execute(['fc-1-' . bin2hex(random_bytes(4)), MailStatus::Failed->value]);
        $stmt->execute(['fc-2-' . bin2hex(random_bytes(4)), MailStatus::Failed->value]);
        // Un `error` réessayable n'est PAS un échec définitif.
        $stmt->execute(['fc-3-' . bin2hex(random_bytes(4)), MailStatus::Error->value]);

        self::assertSame(2, $this->serviceFor($db)->getOutboxFailureCount());
    }

    public function testReturnsNullWhenCountCannotBeRead(): void
    {
        $this->savedTestDbPath = $GLOBALS['_test_db_path'] ?? null;
        // Dossier parent inexistant → l'ouverture SQLite échoue (PDOException).
        $GLOBALS['_test_db_path'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'outbox_no_dir_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR . 'x.db';
        $broken = new Database();

        $service = $this->serviceFor($broken);

        self::assertNull($service->getOutboxFailureCount(), 'une lecture impossible vaut « état inconnu », pas 0');
    }
}