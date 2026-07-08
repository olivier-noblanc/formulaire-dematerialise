# Task 5 Report: Supprimer `lib/auth.php`

## Summary

Deleted `lib/auth.php` and removed its require from `helpers.php`. Verification found remaining callers that will break.

## Changes Made

1. **Deleted**: `lib/auth.php` (66 lines)
2. **Modified**: `helpers.php` — removed `require_once __DIR__ . '/lib/auth.php';` (line 90)

## Verification Results

The verification commands returned non-zero results. Remaining callers:

### Critical (will cause fatal errors)

| File | Line | Call |
|------|------|------|
| `lib/html.php` | 59 | `function_exists('get_auth_user') ? get_auth_user() : ''` |
| `tests/test_advanced_admin.php` | 32, 57, 87, 111, 132, 146, 168, 181, 193, 197, 212 | `process_admin_request()`, `approve_admin_request()`, `reject_admin_request()`, `remove_admin()`, `is_admin_user()`, `get_admin_email()`, `is_super_admin()`, `require_admin()` |
| `tests/test_advanced_forms_files.php` | 63, 71, 72, 78, 84, 92, 95, 102, 103 | `get_form_owners()`, `get_owned_forms()`, `get_admin_email()`, `is_form_owner()`, `is_admin_user()` |

### Documentation/Comments (no runtime impact)

- `lib/core_bootstrap.php:41` — comment
- `lib/docs_section_admin.php:246` — HTML doc
- `lib/docs_section_technique.php:157` — HTML doc
- `src/Auth/AuthService.php:67` — comment
- `src\Controller\IndexController.php:29` — comment
- Various test files — test descriptions and comments

## Lint Results

- `helpers.php` ✅

## Status

⚠️ Completed with caveats — `lib/auth.php` deleted, but 3 files still have runtime callers that will cause fatal errors.

## Recommended Next Steps

1. **`lib/html.php:59`** — Migrate `display_user()` to accept `AuthService` via DI or parameter
2. **`tests/test_advanced_admin.php`** — Migrate all 11 auth wrapper calls to `\App\Core\App::auth()->*`
3. **`tests/test_advanced_forms_files.php`** — Migrate all 9 auth wrapper calls to `\App\Core\App::auth()->*`
