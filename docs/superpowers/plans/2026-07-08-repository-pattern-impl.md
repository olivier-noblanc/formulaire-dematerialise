# Repository Pattern Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Créer une couche Repository qui centralise l'accès aux données, élimine les appels `get_pdo()` dispersés, et enable le mocking pour les tests.

**Architecture:** BaseRepository abstrait avec helpers CRUD + 7 Domain Repositories (Form, Submission, Token, Settings, Admin, Audit, Attachment). Migration itérative par repo avec TDD.

**Tech Stack:** PHP 8.4, PDO SQLite, PHPUnit 13, PHP Modernization (readonly, constructor promotion, union types)

## Global Constraints

- PHP 8.4+ (array_all(), readonly, constructor promotion)
- SQLite via PDO (pas d'ORM)
- 504 tests existants doivent continuer à passer
- TDD : test d'abord, implémentation ensuite
- Auteur commits : `onoblanc <olivier.noblanc@dreets.gouv.fr>`
- `composer dump-autoload` après ajout de fichiers

---

## Phase 1 : BaseRepository + SettingsRepository (Validation du pattern)

### Task 1: BaseRepository

**Files:**
- Create: `src/Repository/BaseRepository.php`
- Test: `tests/PHPUnit/Repository/BaseRepositoryTest.php`

**Interfaces:**
- Produces: `BaseRepository::pdo()`, `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/BaseRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\BaseRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/BaseRepositoryTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/BaseRepository.php tests/PHPUnit/Repository/BaseRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: BaseRepository with PDO helpers (TDD)"
```

---

### Task 2: SettingsRepository

**Files:**
- Create: `src/Repository/SettingsRepository.php`
- Test: `tests/PHPUnit/Repository/SettingsRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `SettingsRepository::get()`, `set()`, `delete()`, `getAll()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SettingsRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\SettingsRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SettingsRepositoryTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/SettingsRepository.php tests/PHPUnit/Repository/SettingsRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: SettingsRepository (TDD)"
```

---

### Task 3: Register SettingsRepository in DI

**Files:**
- Modify: `helpers.php:164`
- Modify: `src/bootstrap.php:50`
- Modify: `tests/phpunit_bootstrap.php:51`

- [ ] **Step 1: Add to helpers.php**

After line 163 (`$_app->set(\App\Cache\CacheService::class, new \App\Cache\CacheService());`), add:

```php
$_app->set(\App\Repository\SettingsRepository::class, new \App\Repository\SettingsRepository($_db_service));
```

- [ ] **Step 2: Add to src/bootstrap.php**

After line 49 (`$app->set(SettingsService::class, new SettingsService($db));`), add:

```php
use App\Repository\SettingsRepository;
$app->set(SettingsRepository::class, new SettingsRepository($db));
```

- [ ] **Step 3: Add to tests/phpunit_bootstrap.php**

After line 51 (`$app->set(SettingsService::class, new SettingsService($db));`), add:

```php
use App\Repository\SettingsRepository;
$app->set(SettingsRepository::class, new SettingsRepository($db));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504 tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register SettingsRepository in DI"
```

---

## Phase 2 : AuditRepository + AttachmentRepository

### Task 4: AuditRepository

**Files:**
- Create: `src/Repository/AuditRepository.php`
- Test: `tests/PHPUnit/Repository/AuditRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `AuditRepository::log()`, `securityLog()`, `getLogs()`, `getSecurityLogs()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AuditRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\AuditRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AuditRepositoryTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/AuditRepository.php tests/PHPUnit/Repository/AuditRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: AuditRepository (TDD)"
```

---

### Task 5: AttachmentRepository

**Files:**
- Create: `src/Repository/AttachmentRepository.php`
- Test: `tests/PHPUnit/Repository/AttachmentRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `AttachmentRepository::findById()`, `findBySubmission()`, `create()`, `delete()`, `deleteBySubmission()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AttachmentRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\AttachmentRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AttachmentRepositoryTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/AttachmentRepository.php tests/PHPUnit/Repository/AttachmentRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: AttachmentRepository (TDD)"
```

---

### Task 6: Register Audit + Attachment in DI

**Files:**
- Modify: `helpers.php:165`
- Modify: `src/bootstrap.php:51`
- Modify: `tests/phpunit_bootstrap.php:52`

- [ ] **Step 1: Add to helpers.php**

After SettingsRepository registration, add:

```php
$_app->set(\App\Repository\AuditRepository::class, new \App\Repository\AuditRepository($_db_service));
$_app->set(\App\Repository\AttachmentRepository::class, new \App\Repository\AttachmentRepository($_db_service));
```

- [ ] **Step 2: Add to src/bootstrap.php**

After SettingsRepository registration, add:

```php
use App\Repository\AuditRepository;
use App\Repository\AttachmentRepository;
$app->set(AuditRepository::class, new AuditRepository($db));
$app->set(AttachmentRepository::class, new AttachmentRepository($db));
```

- [ ] **Step 3: Add to tests/phpunit_bootstrap.php**

After SettingsRepository registration, add:

```php
use App\Repository\AuditRepository;
use App\Repository\AttachmentRepository;
$app->set(AuditRepository::class, new AuditRepository($db));
$app->set(AttachmentRepository::class, new AttachmentRepository($db));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register AuditRepository + AttachmentRepository in DI"
```

---

## Phase 3 : AdminRepository + FormRepository

### Task 7: AdminRepository

**Files:**
- Create: `src/Repository/AdminRepository.php`
- Test: `tests/PHPUnit/Repository/AdminRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `AdminRepository::findByEmail()`, `isAdmin()`, `isSuperAdmin()`, `getAll()`, `add()`, `remove()`, `getPendingRequests()`, `approveRequest()`, `rejectRequest()`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use App\Repository\AdminRepository;
use App\Core\Database;

final class AdminRepositoryTest extends TestCase
{
    private AdminRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AdminRepository(new Database());
    }

    public function testFindByEmailReturnsNullForNonexistent(): void
    {
        $result = $this->repo->findByEmail('nonexistent@test.com');
        $this->assertNull($result);
    }

    public function testIsAdminReturnsBool(): void
    {
        $result = $this->repo->isAdmin('test@test.com');
        $this->assertIsBool($result);
    }

    public function testGetAllReturnsArray(): void
    {
        $result = $this->repo->getAll();
        $this->assertIsArray($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AdminRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\AdminRepository' not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace App\Repository;

final class AdminRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne("SELECT * FROM admins WHERE email = ?", [strtolower($email)]);
    }
    
    public function isAdmin(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }
    
    public function isSuperAdmin(string $email): bool
    {
        $adminEmail = $this->getSuperAdminEmail();
        return strtolower($email) === strtolower($adminEmail);
    }
    
    public function getSuperAdminEmail(): string
    {
        $result = $this->fetchOne("SELECT value FROM settings WHERE key = 'admin_email'");
        return $result['value'] ?? '';
    }
    
    public function getAll(): array
    {
        return $this->fetchAll("SELECT * FROM admins ORDER BY email");
    }
    
    public function add(string $email, string $addedBy): bool
    {
        return $this->execute(
            "INSERT OR IGNORE INTO admins (email, added_at, added_by) VALUES (?, datetime('now'), ?)",
            [strtolower($email), $addedBy]
        );
    }
    
    public function remove(string $email): bool
    {
        return $this->execute("DELETE FROM admins WHERE email = ?", [strtolower($email)]);
    }
    
    public function getPendingRequests(): array
    {
        return $this->fetchAll(
            "SELECT * FROM admin_requests WHERE status = 'pending' ORDER BY requested_at"
        );
    }
    
    public function approveRequest(string $requestId, string $approvedBy): bool
    {
        $request = $this->fetchOne("SELECT * FROM admin_requests WHERE id = ?", [$requestId]);
        if ($request === null) return false;
        
        $this->add($request['email'], $approvedBy);
        return $this->execute(
            "UPDATE admin_requests SET status = 'approved', reviewed_at = datetime('now'), reviewed_by = ? WHERE id = ?",
            [$approvedBy, $requestId]
        );
    }
    
    public function rejectRequest(string $requestId, string $rejectedBy): bool
    {
        return $this->execute(
            "UPDATE admin_requests SET status = 'rejected', reviewed_at = datetime('now'), reviewed_by = ? WHERE id = ?",
            [$rejectedBy, $requestId]
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/AdminRepositoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/AdminRepository.php tests/PHPUnit/Repository/AdminRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: AdminRepository (TDD)"
```

---

### Task 8: FormRepository

**Files:**
- Create: `src/Repository/FormRepository.php`
- Test: `tests/PHPUnit/Repository/FormRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `FormRepository::findById()`, `findBySlug()`, `findAll()`, `findOwnedBy()`, `create()`, `update()`, `delete()`, `getFields()`, `getSteps()`, `getOwners()`, `addOwner()`, `removeOwner()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/FormRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\FormRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/FormRepositoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/FormRepository.php tests/PHPUnit/Repository/FormRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: FormRepository (TDD)"
```

---

### Task 9: Register Admin + Form in DI

**Files:**
- Modify: `helpers.php:167`
- Modify: `src/bootstrap.php:53`
- Modify: `tests/phpunit_bootstrap.php:54`

- [ ] **Step 1: Add to helpers.php**

After AuditRepository registration, add:

```php
$_app->set(\App\Repository\AdminRepository::class, new \App\Repository\AdminRepository($_db_service));
$_app->set(\App\Repository\FormRepository::class, new \App\Repository\FormRepository($_db_service));
```

- [ ] **Step 2: Add to src/bootstrap.php**

After AuditRepository registration, add:

```php
use App\Repository\AdminRepository;
use App\Repository\FormRepository;
$app->set(AdminRepository::class, new AdminRepository($db));
$app->set(FormRepository::class, new FormRepository($db));
```

- [ ] **Step 3: Add to tests/phpunit_bootstrap.php**

After AuditRepository registration, add:

```php
use App\Repository\AdminRepository;
use App\Repository\FormRepository;
$app->set(AdminRepository::class, new AdminRepository($db));
$app->set(FormRepository::class, new FormRepository($db));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register AdminRepository + FormRepository in DI"
```

---

## Phase 4 : SubmissionRepository + TokenRepository

### Task 10: SubmissionRepository

**Files:**
- Create: `src/Repository/SubmissionRepository.php`
- Test: `tests/PHPUnit/Repository/SubmissionRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `SubmissionRepository::findById()`, `findByForm()`, `findBySubmitter()`, `findPendingForValidator()`, `create()`, `updateStatus()`, `getValidatorData()`, `saveValidatorData()`, `deleteValidatorData()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SubmissionRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\SubmissionRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/SubmissionRepositoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/SubmissionRepository.php tests/PHPUnit/Repository/SubmissionRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: SubmissionRepository (TDD)"
```

---

### Task 11: TokenRepository

**Files:**
- Create: `src/Repository/TokenRepository.php`
- Test: `tests/PHPUnit/Repository/TokenRepositoryTest.php`

**Interfaces:**
- Consumes: `BaseRepository`
- Produces: `TokenRepository::findByValue()`, `findById()`, `findBySubmission()`, `create()`, `markUsed()`, `markExpired()`, `incrementRelance()`, `getActiveCount()`, `getActiveCountByStep()`

- [ ] **Step 1: Write the failing test**

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

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/TokenRepositoryTest.php`
Expected: FAIL with "Class 'App\Repository\TokenRepository' not found"

- [ ] **Step 3: Write minimal implementation**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php phpunit.phar tests/PHPUnit/Repository/TokenRepositoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
rtk git add src/Repository/TokenRepository.php tests/PHPUnit/Repository/TokenRepositoryTest.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: TokenRepository (TDD)"
```

---

### Task 12: Register Submission + Token in DI

**Files:**
- Modify: `helpers.php:169`
- Modify: `src/bootstrap.php:55`
- Modify: `tests/phpunit_bootstrap.php:56`

- [ ] **Step 1: Add to helpers.php**

After FormRepository registration, add:

```php
$_app->set(\App\Repository\SubmissionRepository::class, new \App\Repository\SubmissionRepository($_db_service));
$_app->set(\App\Repository\TokenRepository::class, new \App\Repository\TokenRepository($_db_service));
```

- [ ] **Step 2: Add to src/bootstrap.php**

After FormRepository registration, add:

```php
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
$app->set(SubmissionRepository::class, new SubmissionRepository($db));
$app->set(TokenRepository::class, new TokenRepository($db));
```

- [ ] **Step 3: Add to tests/phpunit_bootstrap.php**

After FormRepository registration, add:

```php
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
$app->set(SubmissionRepository::class, new SubmissionRepository($db));
$app->set(TokenRepository::class, new TokenRepository($db));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register SubmissionRepository + TokenRepository in DI"
```

---

## Phase 5 : Migration des services src/

### Task 13: Migrer SettingsService vers SettingsRepository

**Files:**
- Modify: `src/Settings/SettingsService.php:29,59`

- [ ] **Step 1: Inject SettingsRepository**

Ajouter en property et constructor :

```php
private SettingsRepository $repo;

public function __construct(Database $db, SettingsRepository $repo)
{
    $this->db = $db;
    $this->repo = $repo;
}
```

- [ ] **Step 2: Remplacer les appels getPdo()**

Ligne 29 : `$this->db->getPdo()` → `$this->repo->get($key)`
Ligne 59 : `$this->db->getPdo()` → `$this->repo->set($key, $value, $updatedBy)`

- [ ] **Step 3: Mettre à jour le DI**

Dans helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php :
```php
// Avant
$_app->set(\App\Settings\SettingsService::class, new \App\Settings\SettingsService($_db_service));

// Après
$_settings_repo = $_app->get(\App\Repository\SettingsRepository::class);
$_app->set(\App\Settings\SettingsService::class, new \App\Settings\SettingsService($_db_service, $_settings_repo));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add src/Settings/SettingsService.php helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "refactor: SettingsService uses SettingsRepository"
```

---

### Task 14: Migrer AuditLogService vers AuditRepository

**Files:**
- Modify: `src/Audit/AuditLogService.php:33,57`

- [ ] **Step 1: Inject AuditRepository**

Ajouter en property et constructor :

```php
private AuditRepository $repo;

public function __construct(Database $db, AuditRepository $repo)
{
    $this->db = $db;
    $this->repo = $repo;
}
```

- [ ] **Step 2: Remplacer les appels getPdo()**

Ligne 33 : `$this->db->getPdo()` → `$this->repo->log(...)`
Ligne 57 : `$this->db->getPdo()` → `$this->repo->securityLog(...)`

- [ ] **Step 3: Mettre à jour le DI**

Dans helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php :
```php
// Avant
$_app->set(\App\Audit\AuditLogService::class, new \App\Audit\AuditLogService($_db_service));

// Après
$_audit_repo = $_app->get(\App\Repository\AuditRepository::class);
$_app->set(\App\Audit\AuditLogService::class, new \App\Audit\AuditLogService($_db_service, $_audit_repo));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add src/Audit/AuditLogService.php helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "refactor: AuditLogService uses AuditRepository"
```

---

### Task 15: Migrer AttachmentService vers AttachmentRepository

**Files:**
- Modify: `src/Attachment/AttachmentService.php:151,169,183`

- [ ] **Step 1: Inject AttachmentRepository**

Ajouter en property et constructor :

```php
private AttachmentRepository $repo;

public function __construct(Database $db, AttachmentRepository $repo)
{
    $this->db = $db;
    $this->repo = $repo;
}
```

- [ ] **Step 2: Remplacer les appels getPdo()**

Lignes 151, 169, 183 : `$this->db->getPdo()` → `$this->repo->...`

- [ ] **Step 3: Mettre à jour le DI**

Dans helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php :
```php
// Avant
$_app->set(\App\Attachment\AttachmentService::class, new \App\Attachment\AttachmentService($_db_service));

// Après
$_attachment_repo = $_app->get(\App\Repository\AttachmentRepository::class);
$_app->set(\App\Attachment\AttachmentService::class, new \App\Attachment\AttachmentService($_db_service, $_attachment_repo));
```

- [ ] **Step 4: Run all tests**

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

- [ ] **Step 5: Commit**

```bash
rtk git add src/Attachment/AttachmentService.php helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "refactor: AttachmentService uses AttachmentRepository"
```

---

## Phase 6 : Mise à jour documentation

### Task 16: Mettre à jour AGENT.md + CHANGELOG

**Files:**
- Modify: `AGENT.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Ajouter section Repository dans AGENT.md**

Ajouter après la section "Services" :

```markdown
## Repository Pattern

Les repositories centralisent l'accès aux données. Ne pas utiliser `get_pdo()` directement.

### Fichiers
- `src/Repository/BaseRepository.php` — Abstract avec helpers CRUD
- `src/Repository/FormRepository.php` — forms + form_fields + form_owners
- `src/Repository/SubmissionRepository.php` — submissions + validator_data
- `src/Repository/TokenRepository.php` — tokens + delegations
- `src/Repository/SettingsRepository.php` — settings
- `src/Repository/AdminRepository.php` — admins + admin_requests
- `src/Repository/AuditRepository.php` — audit_log + security_log
- `src/Repository/AttachmentRepository.php` — attachments

### Usage
```php
// Via DI
$repo = App::getInstance()->get(FormRepository::class);
$form = $repo->findById($id);

// Dans un service
public function __construct(private FormRepository $forms) {}
```
```

- [ ] **Step 2: Ajouter entrée CHANGELOG**

Ajouter en haut de CHANGELOG.md :

```markdown
## [10.4.0] — 2026-07-08
_Résumé : Repository Pattern — centralisation de l'accès aux données._

### 🏗 Repository Pattern

- **BaseRepository** : abstract avec helpers `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`
- **7 Domain Repositories** : Form, Submission, Token, Settings, Admin, Audit, Attachment
- **Migration** : services src/ utilisent désormais les repositories au lieu de `getPdo()` direct
- **TDD** : tests unitaires pour chaque repository
- **PHP Modernization** : readonly, constructor promotion, union types sur les nouveaux fichiers
```

- [ ] **Step 3: Commit**

```bash
rtk git add AGENT.md CHANGELOG.md
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "docs: Repository Pattern documentation + CHANGELOG v10.4.0"
```

---

## Résumé

| Phase | Tasks | Fichiers créés | Fichiers modifiés |
|-------|-------|----------------|-------------------|
| 1 | 1-3 | 2 | 3 |
| 2 | 4-6 | 2 | 3 |
| 3 | 7-9 | 2 | 3 |
| 4 | 10-12 | 2 | 3 |
| 5 | 13-15 | 0 | 9 |
| 6 | 16 | 0 | 2 |
| **Total** | **16** | **8** | **23** |

## Vérification finale

```bash
rtk php phpunit.phar
# Attendu : 504+ tests PASS

rtk git log --oneline -20
# Attendu : 16 commits propres avec auteur onoblanc
```
