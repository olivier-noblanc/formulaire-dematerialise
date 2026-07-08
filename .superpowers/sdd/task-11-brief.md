# Task 11: TokenRepository

**Files:**
- Create: `src/Repository/TokenRepository.php`
- Test: `tests/PHPUnit/Repository/TokenRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `TokenRepository::findByValue()`, `findById()`, `findBySubmission()`, `create()`, `markUsed()`, `markExpired()`, `incrementRelance()`, `getActiveCount()`, `getActiveCountByStep()`

## Step 1: Write the failing test

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\TokenRepository;
use App\Core\Database;

final class TokenRepositoryTest extends TestCase
{
    private TokenRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new TokenRepository(new Database());
    }

    public function testFindByValueReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findByValue('nonexistent');
        $this->assertNull($result);
    }

    public function testFindBySubmissionReturnsArray(): void
    {
        $result = $this->repo->findBySubmission('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetActiveCountReturnsInt(): void
    {
        $result = $this->repo->getActiveCount('nonexistent');
        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }
}
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/TokenRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\TokenRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class TokenRepository extends BaseRepository
{
    public function findByValue(string $token): ?array
    {
        return $this->fetchOne("SELECT * FROM tokens WHERE token = ?", [$token]);
    }
    
    public function findById(string $tokenId): ?array
    {
        return $this->fetchOne("SELECT * FROM tokens WHERE id = ?", [$tokenId]);
    }
    
    public function findBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT * FROM tokens WHERE submission_id = ? ORDER BY created_at",
            [$submissionId]
        );
    }
    
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO tokens (id, submission_id, step_id, token, to_email, action, status, created_at, expired_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?)",
            [$id, $data['submission_id'], $data['step_id'], $data['token'], $data['to_email'], $data['action'] ?? 'valider', $data['status'] ?? 'pending', $data['expired_at'] ?? null]
        );
        return $id;
    }
    
    public function markUsed(string $tokenId, string $doneBy, ?string $comment = null): bool
    {
        return $this->execute(
            "UPDATE tokens SET used_at = datetime('now'), done_by = ?, comment = ? WHERE id = ?",
            [$doneBy, $comment, $tokenId]
        );
    }
    
    public function markExpired(string $tokenId): bool
    {
        return $this->execute(
            "UPDATE tokens SET expired_at = datetime('now') WHERE id = ?",
            [$tokenId]
        );
    }
    
    public function incrementRelance(string $tokenId): bool
    {
        return $this->execute(
            "UPDATE tokens SET relance_count = relance_count + 1, relance_at = datetime('now') WHERE id = ?",
            [$tokenId]
        );
    }
    
    public function getActiveCount(string $formId): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens t JOIN submissions s ON s.id = t.submission_id WHERE s.form_id = ? AND t.used_at IS NULL AND t.expired_at > datetime('now')",
            [$formId]
        );
        return (int)($result['count'] ?? 0);
    }
    
    public function getActiveCountByStep(string $stepId): int
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as count FROM tokens WHERE step_id = ? AND used_at IS NULL AND expired_at > datetime('now')",
            [$stepId]
        );
        return (int)($result['count'] ?? 0);
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/TokenRepositoryTest.php`
Expected: PASS (3 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/TokenRepository.php tests/PHPUnit/Repository/TokenRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: TokenRepository (TDD)"
```
