# Task 8 Report: Refactor `pages/submission_view.php`

## Summary
Replaced 3 procedural auth wrapper calls with direct DI calls via `App::auth()`.

## Changes Made

| Line | Before | After |
|------|--------|-------|
| 39 | `get_auth_user()` | `App::auth()->getUser()` |
| 40 | `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| 44 | `is_form_owner((string)$sub['form_id'])` | `App::auth()->isFormOwner((string)$sub['form_id'])` |

## Verification
- PHP syntax check: **PASSED** (`php -l pages/submission_view.php`)
- No other procedural wrapper calls remain in this file
- `$user`, `$is_admin`, `$is_form_owner` variables still work as before — only the call source changed
- Context comment `// v9.9.0 — persona: false si admin en mode visu` preserved on `isAdminEffective()` line
