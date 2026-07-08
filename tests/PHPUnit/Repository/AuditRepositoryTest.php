<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AuditRepository;
use App\Core\Database;

final class AuditRepositoryTest extends TestCase
{
    private AuditRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AuditRepository(new Database());
    }

    public function testLogReturnsBool(): void
    {
        $result = $this->repo->log('test_action', 'target', 'detail', 'test@test.com');
        $this->assertTrue($result);
    }

    public function testSecurityLogReturnsBool(): void
    {
        $result = $this->repo->securityLog('test_event', 'detail', 'test@test.com');
        $this->assertTrue($result);
    }

    public function testGetLogsReturnsArray(): void
    {
        $result = $this->repo->getLogs(10);
        $this->assertIsArray($result);
    }

    public function testGetSecurityLogsReturnsArray(): void
    {
        $result = $this->repo->getSecurityLogs(10);
        $this->assertIsArray($result);
    }
}