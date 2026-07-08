<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\BaseRepository;
use App\Core\Database;

final class BaseRepositoryTest extends TestCase
{
    private BaseRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new class(new Database()) extends BaseRepository {};
    }

    public function testPdoReturnsPdoInstance(): void
    {
        $pdo = $this->repo->pdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testFetchOneReturnsArray(): void
    {
        $result = $this->repo->fetchOne("SELECT 1 as id");
        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
    }

    public function testFetchOneReturnsNullOnNoResult(): void
    {
        $result = $this->repo->fetchOne("SELECT * FROM forms WHERE id = ?", ['nonexistent']);
        $this->assertNull($result);
    }

    public function testFetchAllReturnsArray(): void
    {
        $result = $this->repo->fetchAll("SELECT 1 as id UNION SELECT 2 as id");
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testExecuteReturnsBool(): void
    {
        $result = $this->repo->execute("CREATE TEMPORARY TABLE test_repo (id INTEGER)");
        $this->assertTrue($result);
    }
}
