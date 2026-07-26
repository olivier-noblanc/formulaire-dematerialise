# Task 11: Batch P3 — 4 fichiers restants

## Fichiers

1. `pages/admin_forms.php` — `require_admin()`, `get_form_owners()`
2. `pages/admin_settings.php` — `require_admin()`
3. `pages/health.php` — `get_latest_version()`
4. `pages/screenshot.php` — `get_auth_user()`

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `require_admin()` | `App::auth()->requireAdmin()` |
| `get_form_owners($form_id)` | `App::auth()->getFormOwners($form_id)` |
| `get_latest_version()` | `App::cache()->getLatestVersion()` |
| `get_auth_user()` | `App::auth()->getUser()` |

## Testing

```bash
php -l pages/admin_forms.php pages/admin_settings.php pages/health.php pages/screenshot.php
```

## Report

Écrire dans `.superpowers/sdd/task-11-report.md`
