# Task 6 Report: Supprimer `lib/security.php`

## Status: DONE

## What was done

1. **Verified no external callers remain** — grep confirmed all `csrf_field()`, `verify_csrf()`, `require_csrf()`, `generate_csrf_token()` calls outside `lib/security.php` and `src/` were in test files (test names/comments + actual calls). `install.php` uses its own `inst_` prefixed functions, not the wrappers.

2. **Migrated test callers to DI** — 4 test files updated to call `\App\Core\App::security()->*` directly:
   - `tests/test_unit_basics.php` — 6 test calls migrated
   - `tests/test_all.php` — 2 test calls migrated
   - `tests/test_e2e_security_files.php` — 1 test call migrated
   - `tests/PHPUnit/Lib/SecurityLibTest.php` — 7 method calls migrated (added `use App\Core\App` import)

3. **Deleted `lib/security.php`**

4. **Removed requires** from:
   - `helpers.php:96` — removed `require_once __DIR__ . '/lib/security.php';`
   - `lib/core_bootstrap.php:193` — removed `require_once __DIR__ . '/security.php';`

5. **Lint passed** on all modified files:
   - `helpers.php` — ok
   - `tests/test_unit_basics.php` — ok
   - `tests/test_all.php` — ok
   - `tests/test_e2e_security_files.php` — ok
   - `tests/PHPUnit/Lib/SecurityLibTest.php` — ok
   - `lib/core_bootstrap.php` — ok

## Verification

Grep for wrapper function names outside `lib/security.php` and `src/` returns only:
- Test **names** (string arguments in `test('...')`) — not actual calls
- **Comments** referencing the old function names
- `install.php` local `inst_verify_csrf()` — independent implementation, not a wrapper call

Zero actual function calls to the deleted wrappers remain.

## Files modified

- `lib/security.php` — DELETED
- `helpers.php` — removed require
- `lib/core_bootstrap.php` — removed require
- `force-update.ps1` — removed `lib/security.php` from file list
- `tests/test_unit_basics.php` — migrated to DI
- `tests/test_all.php` — migrated to DI
- `tests/test_e2e_security_files.php` — migrated to DI
- `tests/PHPUnit/Lib/SecurityLibTest.php` — migrated to DI
