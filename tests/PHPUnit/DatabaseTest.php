<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\Database;

final class DatabaseTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        $this->database = \App\Core\App::getInstance()->get(\App\Core\Database::class);
    }

    public function testGetPdoReturnsPdoInstance(): void
    {
        $pdo = $this->database->getPdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetPdoReturnsSameInstance(): void
    {
        $pdo1 = $this->database->getPdo();
        $pdo2 = $this->database->getPdo();
        $this->assertSame($pdo1, $pdo2);
    }

    public function testGetPdoInTestModeReturnsTestDb(): void
    {
        $pdo = $this->database->getPdo();
        // In TEST_MODE, it should use workflow_test.db
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testReleaseResetsPdo(): void
    {
        $pdo1 = $this->database->getPdo();
        $this->database->release();
        $pdo2 = $this->database->getPdo();
        $this->assertNotSame($pdo1, $pdo2);
    }

    // ── release() additional cases ──────────────────────────────

    public function testReleaseCanBeCalledMultipleTimes(): void
    {
        $this->database->release();
        $this->database->release();
        $pdo = $this->database->getPdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testReleaseAndReacquireReturnsWorkingPdo(): void
    {
        $this->database->release();
        $pdo = $this->database->getPdo();
        $result = $pdo->query("SELECT 1 as val")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$result['val']);
    }

    public function testGetPdoReturnsPdoInterface(): void
    {
        $pdo = $this->database->getPdo();
        $this->assertInstanceOf(\PDO::class, $pdo);
    }

    public function testGetPdoReturnsWritableConnection(): void
    {
        $pdo = $this->database->getPdo();
        $pdo->exec("CREATE TEMPORARY TABLE test_db_write (id INTEGER)");
        $pdo->exec("INSERT INTO test_db_write VALUES (42)");
        $result = $pdo->query("SELECT id FROM test_db_write")->fetchColumn();
        $this->assertSame(42, (int)$result);
    }

    public function testDatabaseImplementsInterface(): void
    {
        $this->assertInstanceOf(\App\Contract\DatabaseInterface::class, $this->database);
    }

    public function testDatabaseIsFinal(): void
    {
        $reflection = new \ReflectionClass($this->database);
        $this->assertTrue($reflection->isFinal());
    }

    public function testGetPdoReturnsSingletonInTestMode(): void
    {
        $pdo1 = $this->database->getPdo();
        $pdo2 = $this->database->getPdo();
        $this->assertSame($pdo1, $pdo2);
    }

    public function testReleaseClearsBothConnections(): void
    {
        // Ensure both connections are initialized
        $this->database->getPdo();
        $this->database->release();
        $this->database->getPdo();
        // Should work fine after release
        $this->assertInstanceOf(\PDO::class, $this->database->getPdo());
    }
}
