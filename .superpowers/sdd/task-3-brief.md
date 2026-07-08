# Task 3: Migrer `tests/` — Auth wrappers → DI

## Context

Les fichiers de test appellent des wrappers procéduraux pour tester le comportement auth. L'objectif est de les migrer vers les appels DI directs.

## Fichiers à modifier

1. `tests/test_all.php` — `get_auth_user()`, `is_admin_user()`, `is_super_admin()`, `get_admin_email()`
2. `tests/test_unit_basics.php` — `get_auth_user()`, `is_admin_user()`, `is_super_admin()`, `require_admin()`, `get_admin_email()`
3. `tests/test_unit_nav_utils.php` — `get_admin_email()`, `is_form_owner()`, `get_form_owners()`, `get_owned_forms()`
4. `tests/test_refactor.php` — `require_admin()`, `is_admin_user()`, `is_super_admin()`
5. `tests/test_unit_wave8_9.php` — `get_admin_email()`

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |
| `is_super_admin()` | `App::auth()->isSuperAdmin()` |
| `require_admin()` | `App::auth()->requireAdmin()` |
| `get_admin_email()` | `App::auth()->getAdminEmail()` |
| `is_form_owner($id)` | `App::auth()->isFormOwner($id)` |
| `get_form_owners($id)` | `App::auth()->getFormOwners($id)` |
| `get_owned_forms()` | `App::auth()->getOwnedForms()` |

## Constraints

- Les tests testent le comportement des wrappers. En les remplaçant par DI, on teste maintenant les services directement.
- Ajouter `use App\Core\App;` si nécessaire

## Testing

```bash
php -l tests/test_all.php tests/test_unit_basics.php tests/test_unit_nav_utils.php tests/test_refactor.php tests/test_unit_wave8_9.php
```

## Report

Écrire dans `.superpowers/sdd/task-3-report.md`
