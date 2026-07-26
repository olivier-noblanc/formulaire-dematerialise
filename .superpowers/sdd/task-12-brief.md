# Task 12: Register Submission + Token in DI

**Files:**
- Modify: `helpers.php:169`
- Modify: `src/bootstrap.php:55`
- Modify: `tests/phpunit_bootstrap.php:56`

## Step 1: Add to helpers.php

After FormRepository registration, add:

```php
$_app->set(\App\Repository\SubmissionRepository::class, new \App\Repository\SubmissionRepository($_db_service));
$_app->set(\App\Repository\TokenRepository::class, new \App\Repository\TokenRepository($_db_service));
```

## Step 2: Add to src/bootstrap.php

After FormRepository registration, add:

```php
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
$app->set(SubmissionRepository::class, new SubmissionRepository($db));
$app->set(TokenRepository::class, new TokenRepository($db));
```

## Step 3: Add to tests/phpunit_bootstrap.php

After FormRepository registration, add:

```php
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
$app->set(SubmissionRepository::class, new SubmissionRepository($db));
$app->set(TokenRepository::class, new TokenRepository($db));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 504+ tests PASS

## Step 5: Commit

```bash
rtk git add helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "feat: register SubmissionRepository + TokenRepository in DI"
```
