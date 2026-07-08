# Task 13 Report: Migrer SettingsService vers SettingsRepository

## What was implemented

SettingsService now delegates database operations to SettingsRepository instead of calling `getPdo()` directly:

- **SettingsService**: Constructor now accepts `SettingsRepository` as second parameter. `get()` calls `$this->repo->get($key)`, `set()` calls `$this->repo->set($key, $value, $updatedBy)`.
- **SettingsRepository::get()**: Fixed to return `null` for missing keys (was returning `''` due to `?? $default` fallback on null array access).
- **DI files** (helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php): SettingsRepository is registered before SettingsService and injected into its constructor.
- **Test files** (5 files + lib/mail.php fallback): All `new SettingsService($db)` calls updated to pass `new SettingsRepository($db)` as second argument.
- **SettingsRepositoryTest**: Updated assertions to expect `null` for missing keys.

## Test results

```
Tests: 531, Assertions: 821, Deprecations: 3, Skipped: 19
OK, but there were issues!
```

All 531 tests pass. The 3 deprecations and 19 skips are pre-existing and unrelated.

## Files changed

1. `src/Settings/SettingsService.php` — Inject SettingsRepository, replace getPdo() calls
2. `src/Repository/SettingsRepository.php` — Fix get() to return null for missing keys
3. `helpers.php` — Register SettingsRepository before SettingsService, inject it
4. `src/bootstrap.php` — Same DI ordering fix
5. `tests/phpunit_bootstrap.php` — Same DI ordering fix
6. `lib/mail.php` — Fix fallback SettingsService instantiation
7. `tests/PHPUnit/SettingsServiceTest.php` — Pass SettingsRepository to constructor
8. `tests/PHPUnit/TokenServiceTest.php` — Pass SettingsRepository to constructor
9. `tests/PHPUnit/WorkflowEngineTest.php` — Pass SettingsRepository to constructor
10. `tests/PHPUnit/MailerServiceTest.php` — Pass SettingsRepository to constructor
11. `tests/PHPUnit/MailServiceTest.php` — Pass SettingsRepository to constructor
12. `tests/PHPUnit/Repository/SettingsRepositoryTest.php` — Update assertions for null return

## Commit

`2d0df1d refactor: SettingsService uses SettingsRepository`

## Issues / concerns

- The `SettingsRepository::get()` return type changed behavior: it now returns `null` for missing keys instead of the default. This is the correct semantic for a repository (distinguishes "not found" from "empty value"), but it's a breaking change if any external code depends on the old behavior. No internal callers were affected.
- The `$this->db` property in SettingsService is now unused (all DB access goes through `$this->repo`). Kept for backward compatibility in case subclasses exist (none found).
