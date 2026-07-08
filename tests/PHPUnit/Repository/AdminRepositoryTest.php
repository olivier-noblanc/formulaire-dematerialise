<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AdminRepository;
use App\Core\Database;

final class AdminRepositoryTest extends TestCase
{
    private AdminRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AdminRepository(new Database());
    }

    public function testFindByEmailReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findByEmail('nonexistent@test.com');
        $this->assertNull($result);
    }

    public function testIsAdminReturnsBool(): void
    {
        $result = $this->repo->isAdmin('test@test.com');
        $this->assertIsBool($result);
    }

    public function testGetAllReturnsArray(): void
    {
        $result = $this->repo->getAll();
        $this->assertIsArray($result);
    }
}
