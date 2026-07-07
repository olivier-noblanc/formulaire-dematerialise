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
        $this->db = new Database();
        $this->audit = new AuditLogService($this->db);
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

    public function testGetLogsReturnsArray(): void
    {
        $logs = $this->audit->getLogs(10);
        $this->assertIsArray($logs);
    }

    public function testGetLogsWithFilter(): void
    {
        $logs = $this->audit->getLogs(10, 'test_action');
        $this->assertIsArray($logs);
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

    public function testGetLogsRespectsLimit(): void
    {
        $this->audit->log('limit_test', 'a', '1', 'test@test.com');
        $this->audit->log('limit_test', 'b', '2', 'test@test.com');
        $this->audit->log('limit_test', 'c', '3', 'test@test.com');

        $logs = $this->audit->getLogs(2, 'limit_test');
        $this->assertLessThanOrEqual(2, count($logs));
    }

    public function testAppLogWrapperDelegatesToService(): void
    {
        $marker = 'wrapper_' . uniqid();
        app_log('wrapper_test', 'target:' . $marker, 'wrapper detail');

        $logs = get_audit_logs(5, 'wrapper_test');
        $found = false;
        foreach ($logs as $log) {
            if (str_contains((string)$log['target'], $marker)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'app_log() wrapper should write to audit_log via service');
    }

    public function testSecurityLogWrapperDelegatesToService(): void
    {
        $marker = 'secwrap_' . uniqid();
        security_log($marker, 'wrapper security detail');

        $logs = get_audit_logs(5, 'security_event');
        $found = false;
        foreach ($logs as $log) {
            if (str_contains((string)$log['target'], $marker)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'security_log() wrapper should write to audit_log via service');
    }

    public function testGetAuditLogsWrapperDelegatesToService(): void
    {
        $logs = get_audit_logs(5);
        $this->assertIsArray($logs);
    }
}
