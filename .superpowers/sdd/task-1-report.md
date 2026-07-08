# Task 1 Report — Migrer `lib/` — Auth wrappers → DI

**Status**: success
**Date**: 2026-07-08

## Summary

All 6 files in `lib/` migrated from procedural auth wrapper calls to direct DI calls via `\App\Core\App::auth()`.

## Files Modified

| File | Replacements | Notes |
|------|-------------|-------|
| `lib/render_dashboard.php` | 1 | `is_admin_effective()` → `\App\Core\App::auth()->isAdminEffective()` |
| `lib/render_navigation.php` | 4 | `get_auth_user()`, `is_admin_user()`, `is_admin_effective()`, `get_owned_forms()` → FQN DI calls |
| `lib/render_errors.php` | 1 | `get_auth_user()` → `\App\Core\App::auth()->getUser()` |
| `lib/admin_settings_handlers.php` | 6 | All `get_auth_user()` → `App::auth()->getUser()` (already had `use App\Core\App`) |
| `lib/admin_forms_handlers_forms.php` | 2 | `is_form_owner()`, `is_super_admin()` → `App::auth()->isFormOwner()`, `App::auth()->isSuperAdmin()` |
| `lib/render_admin_settings.php` | 2 | `get_admin_email()` → `\App\Core\App::auth()->getAdminEmail()`, `get_auth_user()` → `\App\Core\App::auth()->getUser()` |

**Total**: 16 replacements across 6 files.

## Decisions

- Used FQN (`\App\Core\App::auth()->...`) for files without a `use App\Core\App;` statement (render_dashboard, render_navigation, render_errors, render_admin_settings) — consistent with existing FQN usage in those files.
- Used short form (`App::auth()->...`) for files that already had the `use` statement (admin_settings_handlers, admin_forms_handlers_forms).
- Removed `function_exists('get_auth_user')` guard in `render_errors.php` — no longer needed when calling a static method directly.

## Lint

All 6 files pass `php -l` with no errors.

## Not Modified

- `lib/auth.php` — as specified, left untouched (to be removed in a later task).
- `src/` and `tests/` — as specified, not touched.
