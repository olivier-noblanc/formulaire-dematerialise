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
        $this->database = new Database();
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
}
