# Task 3: Register SettingsRepository in DI

**Files:**
- Modify: `helpers.php:164`
- Modify: `src/bootstrap.php:50`
- Modify: `tests/phpunit_bootstrap.php:51`

## Step 1: Add to helpers.php

After line 163 (`$_app->set(\App\Cache\CacheService::class, new \App\Cache\CacheService());`), add:

```php
$_app->set(\App\Repository\SettingsRepository::class, new \App\Repository\SettingsRepository($_db_service));
```

## Step 2: Add to src/bootstrap.php

After line 49 (`$app->set(SettingsService::class, new SettingsService($db));`), add:

```php
use App\Repository\SettingsRepository;
$app->set(SettingsRepository::class, new SettingsRepository($db));
```

## Step 3: Add to tests/phpunit_bootstrap.php

After line 51 (`$app->set(SettingsService::class, new SettingsService($db));`), add:

```php
use App\Repository\SettingsRepository;
$app->set(SettingsRepository::class, new SettingsRepository($db));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

## Step 5: Commit

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <admin.local@exemple.invalid>" -m "feat: register SettingsRepository in DI"
```
