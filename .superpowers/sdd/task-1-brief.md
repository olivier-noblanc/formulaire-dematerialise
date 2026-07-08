# Task 1: BaseRepository

**Files:**
- Create: `src/Repository/BaseRepository.php`
- Test: `tests/PHPUnit/Repository/BaseRepositoryTest.php`

**Interfaces:**
- Produces: `BaseRepository::pdo()`, `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`

## Step 1: Write the failing test

```php
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
        $this->assertSame('1', $result['id']);
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
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/BaseRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\BaseRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

abstract class BaseRepository
{
    public function __construct(protected Database $db) {}
    
    protected function pdo(): PDO
    {
        return $this->db->getPdo();
    }
    
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
    
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo()->prepare($sql);
        return $stmt->execute($params);
    }
    
    protected function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/BaseRepositoryTest.php`
Expected: PASS (5 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/BaseRepository.php tests/PHPUnit/Repository/BaseRepositoryTest.php
rtk git commit --author="onoblanc <admin.local@exemple.invalid>" -m "feat: BaseRepository with PDO helpers (TDD)"
```
