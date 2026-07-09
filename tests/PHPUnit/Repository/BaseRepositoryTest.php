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

    // ── lastInsertId() ──────────────────────────────────────────

    public function testLastInsertIdReturnsString(): void
    {
        $pdo = $this->repo->pdo();
        $pdo->exec("CREATE TEMPORARY TABLE test_lastid (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT)");
        $pdo->exec("INSERT INTO test_lastid (val) VALUES ('test')");
        $id = $this->repo->lastInsertId();
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        $pdo->exec("DROP TABLE test_lastid");
    }

    // ── fetchAll() with parameters ──────────────────────────────

    public function testFetchAllWithParameters(): void
    {
        $result = $this->repo->fetchAll(
            "SELECT * FROM sqlite_master WHERE type = ? AND name = ?",
            ['table', 'forms']
        );
        $this->assertIsArray($result);
    }

    public function testFetchAllReturnsEmptyForNoMatch(): void
    {
        $result = $this->repo->fetchAll(
            "SELECT * FROM sqlite_master WHERE name = ?",
            ['nonexistent_table_' . uniqid()]
        );
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ── fetchOne() with parameters ──────────────────────────────

    public function testFetchOneWithParameters(): void
    {
        $result = $this->repo->fetchOne(
            "SELECT * FROM sqlite_master WHERE type = ? AND name = ?",
            ['table', 'forms']
        );
        $this->assertIsArray($result);
    }

    public function testFetchOneReturnsNullForNoMatch(): void
    {
        $result = $this->repo->fetchOne(
            "SELECT * FROM sqlite_master WHERE name = ?",
            ['nonexistent_' . uniqid()]
        );
        $this->assertNull($result);
    }

    // ── execute() with parameters ───────────────────────────────

    public function testExecuteWithParameters(): void
    {
        $pdo = $this->repo->pdo();
        $pdo->exec("CREATE TEMPORARY TABLE test_exec_params (id INTEGER, val TEXT)");
        $result = $this->repo->execute(
            "INSERT INTO test_exec_params (id, val) VALUES (?, ?)",
            [1, 'test']
        );
        $this->assertTrue($result);
        $pdo->exec("DROP TABLE test_exec_params");
    }

    // ── pdo() returns consistent instance ───────────────────────

    public function testPdoReturnsSameInstance(): void
    {
        $pdo1 = $this->repo->pdo();
        $pdo2 = $this->repo->pdo();
        $this->assertSame($pdo1, $pdo2);
    }

    // ── fetchOne() returns assoc array ──────────────────────────

    public function testFetchOneReturnsAssocArray(): void
    {
        $result = $this->repo->fetchOne("SELECT 'hello' as greeting, 42 as answer");
        $this->assertArrayHasKey('greeting', $result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertSame('hello', $result['greeting']);
        $this->assertSame(42, $result['answer']);
    }

    // ── fetchAll() returns assoc arrays ─────────────────────────

    public function testFetchAllReturnsAssocArrays(): void
    {
        $result = $this->repo->fetchAll("SELECT 'a' as val UNION ALL SELECT 'b' as val");
        $this->assertCount(2, $result);
        $this->assertSame('a', $result[0]['val']);
        $this->assertSame('b', $result[1]['val']);
    }
}
