# Task 7 — Refactor `pages/download.php`

**File**: `pages/download.php`

## Changes

| Old (procedural) | New (DI) |
|---|---|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |

**Instances replaced**: 2 (lines 42–43 in main flow, lines 183–184 in `export_submission_json()`).

## Verification

- `php -l pages/download.php` — syntax OK, no errors.
