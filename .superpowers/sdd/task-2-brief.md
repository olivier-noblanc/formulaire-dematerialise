# Task 2: SettingsRepository

**Files:**
- Create: `src/Repository/SettingsRepository.php`
- Test: `tests/PHPUnit/Repository/SettingsRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `SettingsRepository::get()`, `set()`, `delete()`, `getAll()`

## Step 1: Write the failing test

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\SettingsRepository;
use App\Core\Database;

final class SettingsRepositoryTest extends TestCase
{
    private SettingsRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new SettingsRepository(new Database());
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = $this->repo->get('nonexistent_key', 'default');
        $this->assertSame('default', $result);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $key = 'test_repo_' . uniqid();
        $this->repo->set($key, 'test_value');
        $result = $this->repo->get($key);
        $this->assertSame('test_value', $result);
    }

    public function testDeleteRemovesKey(): void
    {
        $key = 'test_delete_' . uniqid();
        $this->repo->set($key, 'to_delete');
        $this->repo->delete($key);
        $result = $this->repo->get($key, '');
        $this->assertSame('', $result);
    }

    public function testGetAllReturnsArray(): void
    {
        $result = $this->repo->getAll();
        $this->assertIsArray($result);
    }
}
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SettingsRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\SettingsRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class SettingsRepository extends BaseRepository
{
    public function get(string $key, string $default = ''): ?string
    {
        $result = $this->fetchOne(
            "SELECT value FROM settings WHERE key = ?",
            [$key]
        );
        return $result['value'] ?? $default;
    }
    
    public function set(string $key, string $value, string $updatedBy = ''): bool
    {
        return $this->execute(
            "INSERT OR REPLACE INTO settings (key, value, updated_at, updated_by) VALUES (?, ?, datetime('now'), ?)",
            [$key, $value, $updatedBy]
        );
    }
    
    public function delete(string $key): bool
    {
        return $this->execute("DELETE FROM settings WHERE key = ?", [$key]);
    }
    
    public function getAll(): array
    {
        return $this->fetchAll("SELECT key, value FROM settings ORDER BY key");
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SettingsRepositoryTest.php`
Expected: PASS (4 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/SettingsRepository.php tests/PHPUnit/Repository/SettingsRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: SettingsRepository (TDD)"
```
