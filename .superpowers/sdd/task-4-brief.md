# Task 4: AuditRepository

**Files:**
- Create: `src/Repository/AuditRepository.php`
- Test: `tests/PHPUnit/Repository/AuditRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `AuditRepository::log()`, `securityLog()`, `getLogs()`, `getSecurityLogs()`

## Step 1: Write the failing test

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AuditRepository;
use App\Core\Database;

final class AuditRepositoryTest extends TestCase
{
    private AuditRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AuditRepository(new Database());
    }

    public function testLogReturnsBool(): void
    {
        $result = $this->repo->log('test_action', 'target', 'detail', 'test@test.com');
        $this->assertTrue($result);
    }

    public function testSecurityLogReturnsBool(): void
    {
        $result = $this->repo->securityLog('test_event', 'detail', 'test@test.com');
        $this->assertTrue($result);
    }

    public function testGetLogsReturnsArray(): void
    {
        $result = $this->repo->getLogs(10);
        $this->assertIsArray($result);
    }

    public function testGetSecurityLogsReturnsArray(): void
    {
        $result = $this->repo->getSecurityLogs(10);
        $this->assertIsArray($result);
    }
}
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AuditRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\AuditRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class AuditRepository extends BaseRepository
{
    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): bool
    {
        if ($actor === '' && function_exists('get_auth_user')) {
            $actor = get_auth_user();
        }
        return $this->execute(
            "INSERT INTO audit_log (id, action, target, detail, actor, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [\generate_uuid(), $action, $target, $detail, $actor]
        );
    }
    
    public function securityLog(string $event, string $detail = '', string $actor = ''): bool
    {
        if ($actor === '' && function_exists('get_auth_user')) {
            $actor = get_auth_user();
        }
        return $this->execute(
            "INSERT INTO security_log (id, event, detail, actor, created_at) VALUES (?, ?, ?, ?, datetime('now'))",
            [\generate_uuid(), $event, $detail, $actor]
        );
    }
    
    public function getLogs(int $limit = 100, string $actionFilter = ''): array
    {
        $sql = "SELECT * FROM audit_log";
        $params = [];
        if ($actionFilter !== '') {
            $sql .= " WHERE action = ?";
            $params[] = $actionFilter;
        }
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        return $this->fetchAll($sql, $params);
    }
    
    public function getSecurityLogs(int $limit = 100): array
    {
        return $this->fetchAll(
            "SELECT * FROM security_log ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AuditRepositoryTest.php`
Expected: PASS (4 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/AuditRepository.php tests/PHPUnit/Repository/AuditRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: AuditRepository (TDD)"
```
