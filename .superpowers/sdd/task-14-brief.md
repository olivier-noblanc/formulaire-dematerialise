# Task 14: Migrer AuditLogService vers AuditRepository

**Files:**
- Modify: `src/Audit/AuditLogService.php:33,57`
- Modify: `helpers.php`
- Modify: `src/bootstrap.php`
- Modify: `tests/phpunit_bootstrap.php`

## Step 1: Inject AuditRepository

Ajouter en property et constructor :

```php
private AuditRepository $repo;

public function __construct(Database $db, AuditRepository $repo)
{
    $this->db = $db;
    $this->repo = $repo;
}
```

## Step 2: Remplacer les appels getPdo()

Ligne 33 : `$this->db->getPdo()` → `$this->repo->log(...)`
Ligne 57 : `$this->db->getPdo()` → `$this->repo->securityLog(...)`

## Step 3: Mettre à jour le DI

Dans helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php :
```php
// Avant
$_app->set(\App\Audit\AuditLogService::class, new \App\Audit\AuditLogService($_db_service));

// Après
$_audit_repo = $_app->get(\App\Repository\AuditRepository::class);
$_app->set(\App\Audit\AuditLogService::class, new \App\Audit\AuditLogService($_db_service, $_audit_repo));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 531+ tests PASS

## Step 5: Commit

```bash
rtk git add src/Audit/AuditLogService.php helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "refactor: AuditLogService uses AuditRepository"
```
