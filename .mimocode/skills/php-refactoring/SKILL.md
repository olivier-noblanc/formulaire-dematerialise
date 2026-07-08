# Skill: php-refactoring

## Description

Reusable workflows for PHP refactoring: Repository pattern extraction, Service extraction from lib/ to src/, DI migration, and Test decoupling.

## When to Use

- Extracting a new Repository from scattered `get_pdo()` calls
- Moving procedural lib/ functions to OOP services in src/
- Replacing direct function calls with DI container calls
- Decoupling tests from lib/ files to use DI instead

## Patterns

### 1. Repository Extraction (TDD)

**Trigger:** `get_pdo()` called from multiple files, SQL queries scattered

**Steps:**
1. Create `src/Repository/BaseRepository.php` with PDO helpers
2. Create domain repository extending BaseRepository
3. Write tests first (TDD)
4. Register in DI (helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php)
5. Run `composer dump-autoload`
6. Migrate callers to use repository
7. Commit

**Template:**
```php
// src/Repository/BaseRepository.php
abstract class BaseRepository
{
    public function __construct(protected Database $db) {}
    
    protected function pdo(): PDO { return $this->db->getPdo(); }
    protected function fetchOne(string $sql, array $params = []): ?array { ... }
    protected function fetchAll(string $sql, array $params = []): array { ... }
    protected function execute(string $sql, array $params = []): bool { ... }
}
```

### 2. Service Extraction (lib/ → src/)

**Trigger:** lib/ file has business logic that should be in a service

**Steps:**
1. Read lib/ file to understand functions
2. Create `src/ServiceName/ServiceName.php` with methods
3. Keep lib/ functions as thin wrappers for backward compat
4. Create tests (TDD)
5. Register in DI
6. Run `composer dump-autoload`
7. Verify all tests pass
8. Commit

**Template:**
```php
// lib/legacy.php (thin wrapper)
function legacy_function() {
    return \App\Core\App::serviceName()->method();
}

// src/ServiceName/ServiceName.php
class ServiceName
{
    public function method(): ReturnType { ... }
}
```

### 3. DI Migration (direct calls → container)

**Trigger:** Direct function calls like `get_pdo()`, `app_log()`, `get_setting()`

**Replacements:**
```php
// Before
$pdo = get_pdo();
app_log($action, $target, $detail);
$val = get_setting($key);
set_setting($key, $val);
require_csrf();
$field = csrf_field();
$token = generate_csrf_token();
verify_csrf();
send_security_headers();

// After
$pdo = \App\Core\App::db()->getPdo();
\App\Core\App::audit()->log($action, $target, $detail);
$val = \App\Core\App::settings()->get($key);
\App\Core\App::settings()->set($key, $val);
\App\Core\App::security()->requireCsrf();
$field = \App\Core\App::security()->csrfField();
$token = \App\Core\App::security()->generateCsrfToken();
\App\Core\App::security()->verifyCsrf();
\App\Core\App::security()->sendSecurityHeaders();
```

**Steps:**
1. Search for direct calls in pages/ and lib/
2. Replace with DI container calls
3. Add `use App\Core\App;` if needed
4. Run tests
5. Commit

### 4. Test Decoupling (lib/ → DI)

**Trigger:** Tests import lib/ files directly

**Steps:**
1. Search for `require_once.*lib/` or direct lib/ function calls in tests
2. Replace with DI container calls
3. Ensure test bootstrap registers all services
4. Run tests
5. Commit

## Global Constraints

- PHP 8.4+ (readonly, constructor promotion, union types)
- TDD: test first, implementation second
- Author commits: `onoblanc <admin.local@exemple.invalid>`
- Run `composer dump-autoload` after adding new classes
- Verify all tests pass before committing

## Checklist

- [ ] BaseRepository created (if new repo)
- [ ] Domain repository created
- [ ] Tests written (TDD)
- [ ] DI registration added (3 files)
- [ ] `composer dump-autoload` run
- [ ] Callers migrated
- [ ] All tests pass
- [ ] Committed with correct author
