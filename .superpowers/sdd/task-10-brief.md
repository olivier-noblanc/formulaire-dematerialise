# Task 10: Batch P2 — 9 fichiers restants

## Fichiers

1. `pages/my_submissions.php` — `get_auth_user()`, `display_user()`, `t_jargon()`
2. `pages/my_validations.php` — `get_auth_user()`, `t_jargon()`
3. `pages/validate.php` — `get_auth_user()`, `t_jargon()`, `get_file_icon()`, `format_file_size()`
4. `pages/form_preview.php` — `require_admin()`, `get_auth_user()`
5. `pages/docs.php` — `get_auth_user()`, `is_admin_effective()`, `get_latest_version()`
6. `pages/changelog.php` — `get_latest_version()`, `t_jargon()`
7. `pages/confirm_action.php` — `display_user()`
8. `pages/my_forms.php` — `get_auth_user()`, `get_owned_forms()`, `t_jargon()`
9. `pages/persona.php` — `require_admin()`, `get_auth_user()`

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| `get_owned_forms()` | `App::auth()->getOwnedForms()` |
| `require_admin()` | `App::auth()->requireAdmin()` |
| `display_user($email)` | `App::html()->displayUser($email)` |
| `t_jargon($text)` | `App::html()->tJargon($text)` |
| `get_file_icon($mime)` | `App::html()->getFileIcon($mime)` |
| `format_file_size($bytes)` | `App::html()->formatFileSize($bytes)` |
| `get_latest_version()` | `App::cache()->getLatestVersion()` |

## Testing

```bash
php -l pages/my_submissions.php pages/my_validations.php pages/validate.php pages/form_preview.php pages/docs.php pages/changelog.php pages/confirm_action.php pages/my_forms.php pages/persona.php
```

## Report

Écrire dans `.superpowers/sdd/task-10-report.md`
