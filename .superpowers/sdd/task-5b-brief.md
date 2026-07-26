# Task 5b: Migrer les appelants restants

## Context

Après la vérification, il reste 3 fichiers avec des appels runtime aux wrappers auth :

1. `tests/test_advanced_admin.php` — 11 appels
2. `tests/test_advanced_forms_files.php` — 9 appels
3. `lib/html.php` — 1 appel (avec guard `function_exists`)

## Fichiers à modifier

1. `tests/test_advanced_admin.php`
2. `tests/test_advanced_forms_files.php`
3. `lib/html.php`

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |
| `is_super_admin()` | `App::auth()->isSuperAdmin()` |
| `require_admin()` | `App::auth()->requireAdmin()` |
| `get_admin_email()` | `App::auth()->getAdminEmail()` |
| `is_form_owner($id, $email)` | `App::auth()->isFormOwner($id)` (note: 2e arg optionnel) |
| `get_form_owners($id)` | `App::auth()->getFormOwners($id)` |
| `get_owned_forms($email)` | `App::auth()->getOwnedForms($email)` |

## Testing

```bash
php -l tests/test_advanced_admin.php tests/test_advanced_forms_files.php lib/html.php
```

## Report

Écrire dans `.superpowers/sdd/task-5b-report.md`
