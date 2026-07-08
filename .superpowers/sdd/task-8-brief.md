# Task 8: FormRepository

**Files:**
- Create: `src/Repository/FormRepository.php`
- Test: `tests/PHPUnit/Repository/FormRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `FormRepository::findById()`, `findBySlug()`, `findAll()`, `findOwnedBy()`, `create()`, `update()`, `delete()`, `getFields()`, `getSteps()`, `getOwners()`, `addOwner()`, `removeOwner()`

## Step 1: Write the failing test

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\FormRepository;
use App\Core\Database;

final class FormRepositoryTest extends TestCase
{
    private FormRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new FormRepository(new Database());
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findById('nonexistent');
        $this->assertNull($result);
    }

    public function testFindAllReturnsArray(): void
    {
        $result = $this->repo->findAll();
        $this->assertIsArray($result);
    }

    public function testGetFieldsReturnsArray(): void
    {
        $result = $this->repo->getFields('nonexistent');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
```

## Step 2: Run test to verify it fails

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/FormRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\FormRepository' not found"

## Step 3: Write minimal implementation

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class FormRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM forms WHERE id = ?", [$id]);
    }
    
    public function findBySlug(string $slug): ?array
    {
        return $this->fetchOne("SELECT * FROM forms WHERE slug = ?", [$slug]);
    }
    
    public function findAll(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM forms";
        if ($activeOnly) {
            $sql .= " WHERE actif = 1";
        }
        return $this->fetchAll($sql . " ORDER BY label");
    }
    
    public function findOwnedBy(string $email): array
    {
        return $this->fetchAll(
            "SELECT f.* FROM forms f JOIN form_owners fo ON fo.form_id = f.id WHERE fo.email = ? ORDER BY f.label",
            [$email]
        );
    }
    
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO forms (id, label, slug, description, actif, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['label'], $data['slug'], $data['description'] ?? '', $data['actif'] ?? 1]
        );
        return $id;
    }
    
    public function update(string $id, array $data): bool
    {
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $id;
        return $this->execute("UPDATE forms SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    }
    
    public function delete(string $id): bool
    {
        return $this->execute("DELETE FROM forms WHERE id = ?", [$id]);
    }
    
    public function getFields(string $formId): array
    {
        return $this->fetchAll(
            "SELECT * FROM form_fields WHERE form_id = ? ORDER BY position",
            [$formId]
        );
    }
    
    public function getSteps(string $formId): array
    {
        return $this->fetchAll(
            "SELECT * FROM steps WHERE form_id = ? ORDER BY position",
            [$formId]
        );
    }
    
    public function getOwners(string $formId): array
    {
        return $this->fetchAll(
            "SELECT * FROM form_owners WHERE form_id = ? ORDER BY email",
            [$formId]
        );
    }
    
    public function addOwner(string $formId, string $email): bool
    {
        return $this->execute(
            "INSERT OR IGNORE INTO form_owners (form_id, email, added_at) VALUES (?, ?, datetime('now'))",
            [$formId, strtolower($email)]
        );
    }
    
    public function removeOwner(string $formId, string $email): bool
    {
        return $this->execute(
            "DELETE FROM form_owners WHERE form_id = ? AND email = ?",
            [$formId, strtolower($email)]
        );
    }
}
```

## Step 4: Run test to verify it passes

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/FormRepositoryTest.php`
Expected: PASS (3 tests)

## Step 5: Commit

```bash
rtk git add src/Repository/FormRepository.php tests/PHPUnit/Repository/FormRepositoryTest.php
rtk git commit --author="onoblanc <admin.local@exemple.invalid>" -m "feat: FormRepository (TDD)"
```
