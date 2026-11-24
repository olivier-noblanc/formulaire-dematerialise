<?php
declare(strict_types=1);

namespace App\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseMigrationsOrchestrationTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dbm-orchestration-');
        self::assertNotFalse($path);
        $this->dbPath = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testRequiredMigrationRejectsWrongVersionBeforeNextMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $this->dbPath);

        try {
            _dbm_apply_required($pdo, 35, static fn(PDO $connection, int $version): int => $version, 36);
            self::fail('A migration that does not reach its target must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Migration v36 did not reach version 36', $exception->getMessage());
        }

    }

    public function testRequiredMigrationReturnsTargetVersion(): void
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $this->dbPath);

        self::assertSame(
            36,
            _dbm_apply_required($pdo, 35, static fn(): int => 36, 36)
        );
    }
}
