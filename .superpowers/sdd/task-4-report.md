# Task 4 Report

## 1. Migration: `classes/migrations/schema_initial.php`

**Status**: Done

Replaced `get_admin_email()` with `App::auth()->getAdminEmail()` at line 272. Lint passes clean.

## 2. Verification: External callers of wrappers

### Auth wrappers (`lib/auth.php`) — still called externally

The grep for all auth wrappers (excluding `lib/auth.php`, `lib/html.php`, `lib/security.php`, and `tests/`) returned **non-zero results**:

| File | Function referenced | Nature |
|------|-------------------|--------|
| `lib/html.php:59` | `get_auth_user()` | Active call in `display_user()` |
| `lib/docs_section_technique.php:157` | `get_auth_user()` | Doc text (code snippet in HTML) |
| `lib/docs_section_admin.php:246` | `is_admin_effective()` | Doc text (explanation) |
| `src/Controller/IndexController.php:29` | `is_admin_effective()` | Comment |
| `src/Auth/AuthService.php:67` | `is_admin_user()` | Comment |
| `pages/persona.php:51` | `get_auth_user()` | Comment |
| `lib/core_bootstrap.php:41` | `require_admin()` | Comment |
| `classes/migrations/v16.php:8` | `get_admin_email()` | Comment |

**Verdict**: Active calls remain in `lib/html.php` (which depends on `get_auth_user()`). The rest are doc strings or comments — not runtime callers. The auth wrappers cannot be removed yet due to `lib/html.php`.

### HTML wrappers (`lib/html.php`) — still called externally

**`h()`** — 530+ matches. External active callers in production code:
- `src/Mail/MailerService.php`
- `lib/render_admin_settings.php`
- `lib/render_navigation.php`
- `lib/render_dashboard.php`
- `lib/render_errors.php`
- `lib/admin_forms_handlers_forms.php`
- `lib/admin_settings_handlers.php`
- `lib/docs_section_technique.php`

**`display_user()`** — 29 matches. External active callers:
- `lib/admin_forms_render_workflow.php`
- `lib/render_submission_view_sections.php`
- `lib/admin_forms_render_form.php`
- `src/Token/TokenService.php`

**Verdict**: `h()` and `display_user()` are heavily used across the codebase. Cannot be removed.

### Security wrappers (`lib/security.php`) — partially migrated

**`csrf_field()`** — 1 external active caller:
- `src/Controller/FormController.php:369`

**`csrf_check()`** — 0 external callers. ✅ Ready for removal (but only if `csrf_field()` is also migrated).

**Verdict**: `csrf_field()` is still called from `FormController.php`. Security wrappers cannot be fully removed yet.

## 3. Summary

| Wrapper | External callers remain? | Safe to remove? |
|---------|------------------------|-----------------|
| Auth wrappers | Yes (`lib/html.php` calls `get_auth_user()`) | No |
| `h()` | Yes (10+ files) | No |
| `display_user()` | Yes (4 files) | No |
| `csrf_field()` | Yes (1 file) | No |
| `csrf_check()` | No | Yes |

**Task 4 migration (schema_initial.php)**: ✅ Complete.
**Wrapper removal readiness**: ❌ Not ready — external callers still exist in production code.
