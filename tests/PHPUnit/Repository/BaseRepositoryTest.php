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
        $this->repo = new class(\App\Core\App::getInstance()->get(Database::class)) extends BaseRepository {};
    }

    public function testPdoReturnsPdoInstance(): void
    {
        $pdo = $this->repo->pdo();
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testFetchOneReturnsArray(): void
    {
        $result = $this->repo->fetchOne("SELECT 1 as id");
        self::assertIsArray($result);
        self::assertSame(1, $result['id']);
    }

    public function testFetchOneReturnsNullOnNoResult(): void
    {
        $result = $this->repo->fetchOne("SELECT * FROM forms WHERE id = ?", ['nonexistent']);
        self::assertNull($result);
    }

    public function testFetchAllReturnsArray(): void
    {
        $result = $this->repo->fetchAll("SELECT 1 as id UNION SELECT 2 as id");
        self::assertIsArray($result);
        self::assertCount(2, $result);
    }

    public function testExecuteReturnsBool(): void
    {
        $result = $this->repo->execute("CREATE TEMPORARY TABLE test_repo (id INTEGER)");
        self::assertTrue($result);
    }

    // ── fetchAll() with parameters ──────────────────────────────

    public function testFetchAllWithParameters(): void
    {
        $result = $this->repo->fetchAll(
            "SELECT * FROM sqlite_master WHERE type = ? AND name = ?",
            ['table', 'forms']
        );
        self::assertIsArray($result);
    }

    public function testFetchAllReturnsEmptyForNoMatch(): void
    {
        $result = $this->repo->fetchAll(
            "SELECT * FROM sqlite_master WHERE name = ?",
            ['nonexistent_table_' . uniqid()]
        );
        self::assertIsArray($result);
        self::assertEmpty($result);
    }

    // ── fetchOne() with parameters ──────────────────────────────

    public function testFetchOneWithParameters(): void
    {
        $result = $this->repo->fetchOne(
            "SELECT * FROM sqlite_master WHERE type = ? AND name = ?",
            ['table', 'forms']
        );
        self::assertIsArray($result);
    }

    public function testFetchOneReturnsNullForNoMatch(): void
    {
        $result = $this->repo->fetchOne(
            "SELECT * FROM sqlite_master WHERE name = ?",
            ['nonexistent_' . uniqid()]
        );
        self::assertNull($result);
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
        self::assertTrue($result);
        $pdo->exec("DROP TABLE test_exec_params");
    }

    // ── pdo() returns consistent instance ───────────────────────

    public function testPdoReturnsSameInstance(): void
    {
        $pdo1 = $this->repo->pdo();
        $pdo2 = $this->repo->pdo();
        self::assertSame($pdo1, $pdo2);
    }

    // ── fetchOne() returns assoc array ──────────────────────────

    public function testFetchOneReturnsAssocArray(): void
    {
        $result = $this->repo->fetchOne("SELECT 'hello' as greeting, 42 as answer");
        self::assertArrayHasKey('greeting', $result);
        self::assertArrayHasKey('answer', $result);
        self::assertSame('hello', $result['greeting']);
        self::assertSame(42, $result['answer']);
    }

    // ── fetchAll() returns assoc arrays ─────────────────────────

    public function testFetchAllReturnsAssocArrays(): void
    {
        $result = $this->repo->fetchAll("SELECT 'a' as val UNION ALL SELECT 'b' as val");
        self::assertCount(2, $result);
        self::assertSame('a', $result[0]['val']);
        self::assertSame('b', $result[1]['val']);
    }
}
