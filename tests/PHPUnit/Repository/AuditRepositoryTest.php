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
        $this->repo = new AuditRepository(\App\Core\App::getInstance()->get(Database::class));
    }

    public function testLogReturnsBool(): void
    {
        $result = $this->repo->log('test_action', 'target', 'detail', 'test@test.com');
        self::assertTrue($result);
    }

    public function testGetLogsReturnsArray(): void
    {
        $result = $this->repo->getLogs(10);
        self::assertIsArray($result);
    }

    public function testLogAndReadBackRoundTrip(): void
    {
        $action = 'roundtrip_test_' . substr(\generate_uuid(), 0, 8);
        $target = 'test_target';
        $detail = 'test detail for round-trip';
        $actor = 'tester@test.com';

        $logged = $this->repo->log($action, $target, $detail, $actor);
        self::assertTrue($logged);

        $logs = $this->repo->getLogs(10, $action);
        self::assertNotEmpty($logs);

        $found = false;
        foreach ($logs as $entry) {
            if ($entry['action'] === $action && $entry['actor'] === $actor) {
                self::assertSame($target, $entry['target']);
                self::assertSame($detail, $entry['detail']);
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Logged entry not found in getLogs results');
    }
}