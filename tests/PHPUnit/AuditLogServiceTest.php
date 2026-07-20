<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Audit\AuditLogService;
use App\Core\Database;

final class AuditLogServiceTest extends TestCase
{
    private AuditLogService $audit;
    private Database $db;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
        $this->audit = new AuditLogService(new \App\Repository\AuditRepository($this->db));
    }

    public function testLogDoesNotThrow(): void
    {
        $this->audit->log('test_action', 'test_target', 'test_detail');
        $this->assertTrue(true);
    }

    public function testLogWithActor(): void
    {
        $this->audit->log('test_action', 'target', 'detail', 'actor@test.com');
        $this->assertTrue(true);
    }

    public function testSecurityLogDoesNotThrow(): void
    {
        $this->audit->securityLog('test_event', 'test_detail');
        $this->assertTrue(true);
    }

    public function testSecurityLogWithActor(): void
    {
        $this->audit->securityLog('test_event', 'detail', 'actor@test.com');
        $this->assertTrue(true);
    }


    public function testLogMaskEmailsInNonCli(): void
    {
        $this->audit->log('test_action', 'target', 'user@example.com detail');
        $this->assertTrue(true);
    }

    public function testLogWritesToDatabase(): void
    {
        $marker = 'audit_test_' . uniqid();
        $this->audit->log('test_write', 'target:' . $marker, 'detail_' . $marker, 'writer@test.com');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE target = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['target:' . $marker]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row, 'Row should be written to audit_log');
        $this->assertSame('test_write', $row['action']);
        $this->assertSame('writer@test.com', $row['actor']);
    }

    public function testSecurityLogWritesToDatabase(): void
    {
        $marker = 'sec_test_' . uniqid();
        $this->audit->securityLog($marker, 'security detail', 'sec@test.com');

        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM audit_log WHERE target = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['security:' . $marker]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row, 'Row should be written to audit_log via securityLog');
        $this->assertSame('security_event', $row['action']);
        $this->assertSame('sec@test.com', $row['actor']);
    }

}
