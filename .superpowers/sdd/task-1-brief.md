# Task 1: Migrer `lib/` — Auth wrappers → DI

## Context

Les fichiers dans `lib/` appellent encore des wrappers procéduraux (`get_auth_user()`, `is_admin_user()`, etc.) définis dans `lib/auth.php`. Ces wrappers délèguent déjà aux services DI (`App::auth()`), mais l'objectif est de supprimer la couche procédurale.

## Fichiers à modifier

1. `lib/render_dashboard.php` — `is_admin_effective()` (1 occurrence)
2. `lib/render_navigation.php` — `get_auth_user()`, `is_admin_user()`, `is_admin_effective()`, `get_owned_forms()` (4 occurrences)
3. `lib/render_errors.php` — `get_auth_user()` (1 occurrence)
4. `lib/admin_settings_handlers.php` — `get_auth_user()` (6 occurrences)
5. `lib/admin_forms_handlers_forms.php` — `is_form_owner()`, `is_super_admin()` (2 occurrences)
6. `lib/render_admin_settings.php` — `get_admin_email()`, `get_auth_user()` (2 occurrences)

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |
| `is_super_admin()` | `App::auth()->isSuperAdmin()` |
| `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| `is_form_owner($id)` | `App::auth()->isFormOwner($id)` |
| `get_form_owners($id)` | `App::auth()->getFormOwners($id)` |
| `get_owned_forms()` | `App::auth()->getOwnedForms()` |
| `get_admin_email()` | `App::auth()->getAdminEmail()` |

## Constraints

- Ajouter `use App\Core\App;` si nécessaire
- Ne PAS modifier `lib/auth.php` (sera supprimé plus tard)
- Ne PAS modifier les fichiers `src/` ou `tests/`

## Testing

```bash
php -l lib/render_dashboard.php lib/render_navigation.php lib/render_errors.php lib/admin_settings_handlers.php lib/admin_forms_handlers_forms.php lib/render_admin_settings.php
```

## Report

Écrire dans `.superpowers/sdd/task-1-report.md`
