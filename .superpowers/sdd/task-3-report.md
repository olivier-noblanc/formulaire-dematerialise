# Task 3 Report: Migrer `tests/` — Auth wrappers → DI

## Summary

Migrated 4 test files (5th had no actual auth calls to replace) from procedural auth wrapper calls to direct `App::auth()` DI calls.

## Files Modified

| File | Wrappers Replaced | Count |
|------|------------------|-------|
| `tests/test_all.php` | `get_auth_user()`, `get_admin_email()`, `is_admin_user()`, `is_super_admin()` | 5 |
| `tests/test_unit_basics.php` | `get_auth_user()` (4×), `get_admin_email()` (2×), `is_admin_user()` (3×), `is_super_admin()` (2×) | 11 |
| `tests/test_unit_nav_utils.php` | `get_admin_email()` (2×), `is_form_owner()` (1×), `get_form_owners()` (1×), `get_owned_forms()` (1×) | 5 |
| `tests/test_unit_wave8_9.php` | `get_admin_email()` (2×) | 2 |
| `tests/test_refactor.php` | *(no actual calls — all are string-based source checks)* | 0 |

## Mapping Applied

| Old Wrapper | New DI Call |
|-------------|-------------|
| `get_auth_user()` | `\App\Core\App::auth()->getUser()` |
| `is_admin_user()` | `\App\Core\App::auth()->isAdmin()` |
| `is_super_admin()` | `\App\Core\App::auth()->isSuperAdmin()` |
| `get_admin_email()` | `\App\Core\App::auth()->getAdminEmail()` |
| `is_form_owner($id, $email)` | `\App\Core\App::auth()->isFormOwner($id, $email)` |
| `get_form_owners($id)` | `\App\Core\App::auth()->getFormOwners($id)` |
| `get_owned_forms($email)` | `\App\Core\App::auth()->getOwnedForms($email)` |

## Notes

- `tests/test_refactor.php` was excluded from replacements: all auth references are string-based source code inspections (`strpos`, `function_exists`), not actual function calls. The test validates that helpers.php contains `require_admin()` and that admin pages use it — these are structural checks, not runtime calls.
- `require_admin()` calls were not found in any of the 5 target files (it's used in page files like `admin_forms.php`, not in the test files themselves).
- No `use App\Core\App;` import was needed — all files already use the FQCN `\App\Core\App::` inline.

## Lint Results

All 5 files pass `php -l` syntax check:
- `tests/test_all.php` ✅
- `tests/test_unit_basics.php` ✅
- `tests/test_unit_nav_utils.php` ✅
- `tests/test_refactor.php` ✅
- `tests/test_unit_wave8_9.php` ✅
