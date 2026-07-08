# Task 5: AttachmentRepository

**Files:**
- Create: `src/Repository/AttachmentRepository.php`
- Test: `tests/PHPUnit/Repository/AttachmentRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `AttachmentRepository::findById()`, `findBySubmission()`, `create()`, `delete()`, `deleteBySubmission()`

## Step 1: Write the failing test

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AttachmentRepository;
use App\Core\Database;

final class AttachmentRepositoryTest extends TestCase
{
    private AttachmentRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AttachmentRepository(new Database());
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        $this->assertNull($result);
    }

    public function testFindBySubmissionReturnsArray(): void
    {
        $result = $this->repo->findBySubmission('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AttachmentRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\AttachmentRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class AttachmentRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM attachments WHERE id = ?", [$id]);
    }
    
    public function findBySubmission(string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT * FROM attachments WHERE submission_id = ? ORDER BY created_at",
            [$submissionId]
        );
    }
    
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO attachments (id, submission_id, field_name, filename, mime_type, size, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['submission_id'], $data['field_name'], $data['filename'], $data['mime_type'], $data['size'], $data['data']]
        );
        return $id;
    }
    
    public function delete(string $id): bool
    {
        return $this->execute("DELETE FROM attachments WHERE id = ?", [$id]);
    }
    
    public function deleteBySubmission(string $submissionId): bool
    {
        return $this->execute("DELETE FROM attachments WHERE submission_id = ?", [$submissionId]);
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AttachmentRepositoryTest.php`
Expected: PASS (2 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/AttachmentRepository.php tests/PHPUnit/Repository/AttachmentRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: AttachmentRepository (TDD)"
```
