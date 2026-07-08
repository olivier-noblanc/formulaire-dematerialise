# Task 2: Migrer `src/` — Auth wrappers → DI

## Context

Les fichiers dans `src/` appellent encore des wrappers procéduraux. L'objectif est de les migrer vers les appels DI directs.

## Fichiers à modifier

1. `src/Controller/DashboardController.php` — `require_admin()`, `is_admin_user()`
2. `src/Controller/IndexController.php` — `is_admin_effective()`, `get_owned_forms()`
3. `src/Audit/AuditLogService.php` — `get_auth_user()` (2 occurrences)
4. `src/Repository/AuditRepository.php` — `get_auth_user()` (2 occurrences)
5. `src/Mail/MailerService.php` — `get_auth_user()` (1 occurrence)
6. `src/Rgpd/RgpdService.php` — `get_auth_user()`, `is_admin_user()`, `is_super_admin()` (6 occurrences)
7. `src/Render/HtmlService.php` — `get_auth_user()` (1 occurrence dans `displayUser()`)

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |
| `is_super_admin()` | `App::auth()->isSuperAdmin()` |
| `require_admin()` | `App::auth()->requireAdmin()` |
| `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| `get_owned_forms()` | `App::auth()->getOwnedForms()` |

## Constraints

- Les fichiers `src/` ont déjà `use App\Core\App;` — vérifier avant d'ajouter
- Supprimer les guards `function_exists('get_auth_user')` si présents

## Testing

```bash
php -l src/Controller/DashboardController.php src/Controller/IndexController.php src/Audit/AuditLogService.php src/Repository/AuditRepository.php src/Mail/MailerService.php src/Rgpd/RgpdService.php src/Render/HtmlService.php
```

## Report

Écrire dans `.superpowers/sdd/task-2-report.md`
