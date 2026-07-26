# Task 5b Report — Migrate remaining procedural auth callers

## Status: DONE

## Files modified

| File | Calls migrated | Details |
|------|---------------|---------|
| `tests/test_advanced_admin.php` | 7 | `is_admin_user()` x2 → `isAdmin()`, `get_admin_email()` x2 → `getAdminEmail()`, `is_super_admin()` x2 → `isSuperAdmin()`, `require_admin()` → `requireAdmin()` |
| `tests/test_advanced_forms_files.php` | 7 | `get_form_owners()` → `getFormOwners()`, `get_admin_email()` x2 → `getAdminEmail()`, `get_owned_forms()` → `getOwnedForms()`, `is_form_owner()` x2 → `isFormOwner()` (2nd arg dropped), `is_admin_user()` → `isAdmin()` |
| `lib/html.php` | 1 | `get_auth_user()` with `function_exists` guard → `App::auth()->getUser()` (DI only, no fallback) |

## Changes beyond mapping

- **Pre-existing lint fix** in `tests/test_advanced_admin.php` line 210: `escapeshellarg()` call had mismatched single-quotes. Changed outer quotes to double-quotes so the PHP expression inside is properly delimited.
- **`lib/html.php` `display_user()`**: removed `function_exists('get_auth_user')` guard; now calls `\App\Core\App::auth()->getUser()` directly (DI required).

## Lint

```
php -l tests/test_advanced_admin.php   ✓
php -l tests/test_advanced_forms_files.php ✓
php -l lib/html.php                     ✓
```

## Note

The brief stated 11 calls in `test_advanced_admin.php` and 9 in `test_advanced_forms_files.php`. Actual counts found and migrated: 7 + 7 = 14 total across all files. The discrepancy may reflect earlier partial migrations.
