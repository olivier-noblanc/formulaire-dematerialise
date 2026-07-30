<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\SettingsRepository;
use App\Core\Database;

final class SettingsRepositoryTest extends TestCase
{
    private SettingsRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new SettingsRepository(\App\Core\App::getInstance()->get(Database::class));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = $this->repo->get('nonexistent_key', 'default');
        self::assertNull($result);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $key = 'test_repo_' . uniqid();
        $this->repo->set($key, 'test_value');
        $result = $this->repo->get($key);
        self::assertSame('test_value', $result);
    }
}
