# Task 14 Report: Migrer AuditLogService vers AuditRepository

## What was implemented

Migrated `AuditLogService` to use `AuditRepository` instead of calling `getPdo()` directly.

### Changes made:

1. **`src/Audit/AuditLogService.php`** — Added `AuditRepository $repo` property and constructor parameter. Replaced direct PDO calls in `log()` and `getLogs()` with delegations to `$this->repo->log()` and `$this->repo->getLogs()`. Removed unused `generateUuid()` method. Business logic (email masking, actor defaults, error logging) retained in service.

2. **`helpers.php`** — Updated DI registration to pass `AuditRepository` to `AuditLogService`. Fixed ordering: moved `AuditLogService` registration after `AuditRepository` registration.

3. **`src/bootstrap.php`** — Updated DI registration to pass `AuditRepository` to `AuditLogService`.

4. **`tests/phpunit_bootstrap.php`** — Updated DI registration to pass `AuditRepository` to `AuditLogService`.

5. **`tests/PHPUnit/AuditLogServiceTest.php`** — Updated direct construction to pass `AuditRepository`.

6. **`tests/PHPUnit/TokenServiceTest.php`** — Updated direct construction to pass `AuditRepository`.

## Test results

- **531 tests, 821 assertions, 0 failures, 0 errors**
- 3 deprecation warnings (pre-existing)
- 19 skipped tests (pre-existing)

## Commit

- SHA: `1e81879`
- Message: `refactor: AuditLogService uses AuditRepository`
- Author: `onoblanc <admin.local@exemple.invalid>`
- Files: 6 changed, 13 insertions, 27 deletions

## Notes

- The `Database $db` property is kept in the constructor per the task brief, even though it's no longer used directly by the service (the repository handles DB access).
- The service retains business logic (email masking, actor default resolution, error logging) while the repository handles persistence.
- The `securityLog()` method now delegates to `$this->log()` which delegates to `$this->repo->log()`, maintaining the same behavior chain.
