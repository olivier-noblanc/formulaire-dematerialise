# Task 2 — Migrated `src/` files to direct DI calls

## Summary

Replaced 16 procedural auth wrapper calls with `App::auth()->*` DI calls across 7 files. Removed all `function_exists('get_auth_user')` guards.

## Files modified

| File | Changes |
|------|---------|
| `src/Controller/DashboardController.php` | 4 replacements: `require_admin()` → `App::auth()->requireAdmin()`, 3× `is_admin_user()` → `App::auth()->isAdmin()` |
| `src/Controller/IndexController.php` | 2 replacements: `is_admin_effective()` → `App::auth()->isAdminEffective()`, `get_owned_forms($user)` → `App::auth()->getOwnedForms($user)`. Added `use App\Core\App;` |
| `src/Audit/AuditLogService.php` | 2 replacements: `get_auth_user()` → `App::auth()->getUser()` (with guard removal). Added `use App\Core\App;` |
| `src/Repository/AuditRepository.php` | 2 replacements: `get_auth_user()` → `App::auth()->getUser()` (with guard removal). Added `use App\Core\App;` |
| `src/Mail/MailerService.php` | 1 replacement: `get_auth_user()` → `App::auth()->getUser()` |
| `src/Rgpd/RgpdService.php` | 6 replacements: 2× `get_auth_user()` → `App::auth()->getUser()`, 2× `is_admin_user()` → `App::auth()->isAdmin()`, 2× `is_super_admin()` → `App::auth()->isSuperAdmin()` (with guard removal) |
| `src/Render/HtmlService.php` | 1 replacement: `get_auth_user()` → `App::auth()->getUser()` (with guard removal). Added `use App\Core\App;` |

## Lint results

All 7 files pass `php -l` with no errors.

## Guard removal

- `AuditLogService.php`: removed `function_exists('get_auth_user')` ternary in `log()` and `securityLog()`
- `AuditRepository.php`: removed `function_exists('get_auth_user')` condition in `log()` and `securityLog()`
- `RgpdService.php`: removed `function_exists('get_auth_user')`, `function_exists('is_admin_user')`, `function_exists('is_super_admin')` in `exportUserData()` and `deleteUserData()`
- `HtmlService.php`: removed `function_exists('get_auth_user')` in `displayUser()`

## Remaining procedural calls in `src/`

None — grep confirms zero remaining procedural auth calls in the modified files. The only hits are in comments (docstrings referencing old function names).
