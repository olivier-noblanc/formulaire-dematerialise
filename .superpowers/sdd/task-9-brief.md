# Task 9: Register Admin + Form in DI

**Files:**
- Modify: `helpers.php:167`
- Modify: `src/bootstrap.php:53`
- Modify: `tests/phpunit_bootstrap.php:54`

## Step 1: Add to helpers.php

After AuditRepository registration, add:

```php
$_app->set(\App\Repository\AdminRepository::class, new \App\Repository\AdminRepository($_db_service));
$_app->set(\App\Repository\FormRepository::class, new \App\Repository\FormRepository($_db_service));
```

## Step 2: Add to src/bootstrap.php

After AuditRepository registration, add:

```php
use App\Repository\AdminRepository;
use App\Repository\FormRepository;
$app->set(AdminRepository::class, new AdminRepository($db));
$app->set(FormRepository::class, new FormRepository($db));
```

## Step 3: Add to tests/phpunit_bootstrap.php

After AuditRepository registration, add:

```php
use App\Repository\AdminRepository;
use App\Repository\FormRepository;
$app->set(AdminRepository::class, new AdminRepository($db));
$app->set(FormRepository::class, new FormRepository($db));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

## Step 5: Commit

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register AdminRepository + FormRepository in DI"
```
