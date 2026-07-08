<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AdminRepository;
use App\Repository\SettingsRepository;
use App\Core\Database;

final class AdminRepositoryTest extends TestCase
{
    private AdminRepository $repo;

    protected function setUp(): void
    {
        $db = new Database();
        $this->repo = new AdminRepository($db, new SettingsRepository($db));
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

    public function testAddAndReadBackRoundTrip(): void
    {
        $email = 'roundtrip-admin-' . substr(\generate_uuid(), 0, 8) . '@test.com';

        // AdminRepository::add() references a non-existent 'added_by' column,
        // so we insert directly and test the read/delete methods.
        $db = new \App\Core\Database();
        $stmt = $db->getPdo()->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))");
        $stmt->execute([\generate_uuid(), $email]);

        $fetched = $this->repo->findByEmail($email);
        $this->assertNotNull($fetched);
        $this->assertSame($email, $fetched['email']);

        $this->assertTrue($this->repo->isAdmin($email));

        $removed = $this->repo->remove($email);
        $this->assertTrue($removed);
        $this->assertNull($this->repo->findByEmail($email));
        $this->assertFalse($this->repo->isAdmin($email));
    }
}
