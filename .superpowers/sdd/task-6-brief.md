# Task 6: Register Audit + Attachment in DI

**Files:**
- Modify: `helpers.php:165`
- Modify: `src/bootstrap.php:51`
- Modify: `tests/phpunit_bootstrap.php:52`

## Step 1: Add to helpers.php

After SettingsRepository registration, add:

```php
$_app->set(\App\Repository\AuditRepository::class, new \App\Repository\AuditRepository($_db_service));
$_app->set(\App\Repository\AttachmentRepository::class, new \App\Repository\AttachmentRepository($_db_service));
```

## Step 2: Add to src/bootstrap.php

After SettingsRepository registration, add:

```php
use App\Repository\AuditRepository;
use App\Repository\AttachmentRepository;
$app->set(AuditRepository::class, new AuditRepository($db));
$app->set(AttachmentRepository::class, new AttachmentRepository($db));
```

## Step 3: Add to tests/phpunit_bootstrap.php

After SettingsRepository registration, add:

```php
use App\Repository\AuditRepository;
use App\Repository\AttachmentRepository;
$app->set(AuditRepository::class, new AuditRepository($db));
$app->set(AttachmentRepository::class, new AttachmentRepository($db));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

## Step 5: Commit

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register AuditRepository + AttachmentRepository in DI"
```
