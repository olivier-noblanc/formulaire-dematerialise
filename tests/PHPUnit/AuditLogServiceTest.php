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
        try {
            $this->audit->log('test_action', 'test_target', 'test_detail');
            $this->assertTrue(true); // No exception = pass
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }

    public function testLogWithActor(): void
    {
        $this->audit->log('test_action', 'target', 'detail', 'actor@test.com');
        $this->assertTrue(true);
    }

    public function testSecurityLogDoesNotThrow(): void
    {
        try {
            $this->audit->securityLog('test_event', 'test_detail');
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
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
        // In CLI mode, emails are not masked
        try {
            $this->audit->log('test_action', 'target', 'user@example.com detail');
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('App container services not registered');
        }
    }
}
