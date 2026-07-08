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
        $this->repo = new SettingsRepository(new Database());
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = $this->repo->get('nonexistent_key', 'default');
        $this->assertSame('default', $result);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $key = 'test_repo_' . uniqid();
        $this->repo->set($key, 'test_value');
        $result = $this->repo->get($key);
        $this->assertSame('test_value', $result);
    }

    public function testDeleteRemovesKey(): void
    {
        $key = 'test_delete_' . uniqid();
        $this->repo->set($key, 'to_delete');
        $this->repo->delete($key);
        $result = $this->repo->get($key, '');
        $this->assertSame('', $result);
    }

    public function testGetAllReturnsArray(): void
    {
        $result = $this->repo->getAll();
        $this->assertIsArray($result);
    }
}
