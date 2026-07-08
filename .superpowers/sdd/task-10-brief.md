# Task 10: SubmissionRepository

**Files:**
- Create: `src/Repository/SubmissionRepository.php`
- Test: `tests/PHPUnit/Repository/SubmissionRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `SubmissionRepository::findById()`, `findByForm()`, `findBySubmitter()`, `findPendingForValidator()`, `create()`, `updateStatus()`, `getValidatorData()`, `saveValidatorData()`, `deleteValidatorData()`

## Step 1: Write the failing test

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\SubmissionRepository;
use App\Core\Database;

final class SubmissionRepositoryTest extends TestCase
{
    private SubmissionRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new SubmissionRepository(new Database());
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        $this->assertNull($result);
    }

    public function testFindByFormReturnsArray(): void
    {
        $result = $this->repo->findByForm('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetValidatorDataReturnsArray(): void
    {
        $result = $this->repo->getValidatorData('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SubmissionRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\SubmissionRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class SubmissionRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM submissions WHERE id = ?", [$id]);
    }
    
    public function findByForm(string $formId, ?string $status = null): array
    {
        $sql = "SELECT * FROM submissions WHERE form_id = ?";
        $params = [$formId];
        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        return $this->fetchAll($sql . " ORDER BY submitted_at DESC", $params);
    }
    
    public function findBySubmitter(string $email): array
    {
        return $this->fetchAll(
            "SELECT * FROM submissions WHERE submitted_by = ? ORDER BY submitted_at DESC",
            [$email]
        );
    }
    
    public function findPendingForValidator(string $email): array
    {
        return $this->fetchAll(
            "SELECT s.*, t.id as token_id, t.step_id, t.action 
             FROM submissions s 
             JOIN tokens t ON t.submission_id = s.id 
             WHERE t.to_email = ? AND t.used_at IS NULL AND t.expired_at > datetime('now')
             ORDER BY t.created_at",
            [$email]
        );
    }
    
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['form_id'], $data['data'], $data['submitted_by'], $data['status'] ?? 'en_cours']
        );
        return $id;
    }
    
    public function updateStatus(string $id, string $status): bool
    {
        return $this->execute(
            "UPDATE submissions SET status = ? WHERE id = ?",
            [$status, $id]
        );
    }
    
    public function getValidatorData(string $submissionId, ?string $stepId = null): array
    {
        $sql = "SELECT * FROM submission_validator_data WHERE submission_id = ?";
        $params = [$submissionId];
        if ($stepId !== null) {
            $sql .= " AND step_id = ?";
            $params[] = $stepId;
        }
        return $this->fetchAll($sql . " ORDER BY filled_at", $params);
    }
    
    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void
    {
        $this->execute(
            "INSERT OR REPLACE INTO submission_validator_data (submission_id, field_name, value, filled_by_email, step_id, filled_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [$submissionId, $fieldName, $value, $filledBy, $stepId]
        );
    }
    
    public function deleteValidatorData(string $submissionId, string $fieldName): void
    {
        $this->execute(
            "DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?",
            [$submissionId, $fieldName]
        );
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SubmissionRepositoryTest.php`
Expected: PASS (3 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/SubmissionRepository.php tests/PHPUnit/Repository/SubmissionRepositoryTest.php
rtk git commit --author="onoblanc <admin.local@exemple.invalid>" -m "feat: SubmissionRepository (TDD)"
```
