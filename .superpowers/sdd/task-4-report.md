# Task 4: AuditRepository — Report

## What I implemented

**`src/Repository/AuditRepository.php`** — extends `BaseRepository` with four methods:

| Method | Description | Table |
|---|---|---|
| `log($action, $target, $detail, $actor): bool` | Insert audit entry | `audit_log` |
| `securityLog($event, $detail, $actor): bool` | Insert security event as `action='security_event'` | `audit_log` |
| `getLogs($limit, $actionFilter): array` | Fetch audit logs, optional action filter | `audit_log` |
| `getSecurityLogs($limit): array` | Fetch security logs only | `audit_log` |

**Key design decision**: The brief's implementation referenced a `security_log` table that does not exist in the schema. The existing `AuditLogService` writes security logs into `audit_log` with `action='security_event'` and `target='security:' . $event`. I matched this existing pattern rather than creating a phantom table reference.

## What I tested

**`tests/PHPUnit/Repository/AuditRepositoryTest.php`** — 4 tests:

1. `testLogReturnsBool` — `log()` returns `true` on successful INSERT
2. `testSecurityLogReturnsBool` — `securityLog()` returns `true` on successful INSERT
3. `testGetLogsReturnsArray` — `getLogs(10)` returns an array
4. `testGetSecurityLogsReturnsArray` — `getSecurityLogs(10)` returns an array

**Results**: All 4 tests pass. Full Repository test suite: 13/13 pass.

## TDD Evidence

- **RED**: Created test file first → ran `phpunit AuditRepositoryTest.php` → 4 errors: `Class "App\Repository\AuditRepository" not found`
- **GREEN**: Implemented `AuditRepository.php` → ran `phpunit AuditRepositoryTest.php` → 4 tests, 4 assertions, OK

## Files changed

| File | Action |
|---|---|
| `src/Repository/AuditRepository.php` | Created |
| `tests/PHPUnit/Repository/AuditRepositoryTest.php` | Created |

## Commit

`479c300` — `feat: AuditRepository (TDD)` (author: onoblanc)

## Concerns

1. **Brief's `security_log` table reference is a schema mismatch.** The brief's Step 3 implementation uses `INSERT INTO security_log`, but no such table exists in any migration. The existing `AuditLogService::securityLog()` writes to `audit_log` with `action='security_event'`. I followed the real schema to avoid runtime SQL errors. If a separate `security_log` table is desired later, a migration + schema change would be needed.
2. **`get_auth_user()` fallback.** The `log()` and `securityLog()` methods call `get_auth_user()` if `$actor` is empty. In CLI/test context, this function may return empty or 'system' depending on session state. This matches the existing `AuditLogService` behavior.
