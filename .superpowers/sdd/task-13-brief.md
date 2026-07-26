# Task 13: Migrer SettingsService vers SettingsRepository

**Files:**
- Modify: `src/Settings/SettingsService.php:29,59`
- Modify: `helpers.php`
- Modify: `src/bootstrap.php`
- Modify: `tests/phpunit_bootstrap.php`

## Step 1: Inject SettingsRepository

Ajouter en property et constructor :

```php
private SettingsRepository $repo;

public function __construct(Database $db, SettingsRepository $repo)
{
    $this->db = $db;
    $this->repo = $repo;
}
```

## Step 2: Remplacer les appels getPdo()

Ligne 29 : `$this->db->getPdo()` → `$this->repo->get($key)`
Ligne 59 : `$this->db->getPdo()` → `$this->repo->set($key, $value, $updatedBy)`

## Step 3: Mettre à jour le DI

Dans helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php :
```php
// Avant
$_app->set(\App\Settings\SettingsService::class, new \App\Settings\SettingsService($_db_service));

// Après
$_settings_repo = $_app->get(\App\Repository\SettingsRepository::class);
$_app->set(\App\Settings\SettingsService::class, new \App\Settings\SettingsService($_db_service, $_settings_repo));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 531+ tests PASS

## Step 5: Commit

```bash
rtk git add src/Settings/SettingsService.php helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <admin.local@exemple.invalid>" -m "refactor: SettingsService uses SettingsRepository"
```
