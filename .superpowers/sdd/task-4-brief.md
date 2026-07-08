# Task 4: Migrer `classes/` + vérifier `lib/html.php` + `lib/security.php`

## Context

Dernière étape avant de supprimer les wrappers procéduraux. Vérifier que `classes/migrations/schema_initial.php` utilise DI, et que `lib/html.php` et `lib/security.php` n'ont plus d'appels externes.

## Fichiers à modifier

1. `classes/migrations/schema_initial.php` — `get_admin_email()` (1 occurrence)

## Fichiers à vérifier (pas modifier)

2. `lib/html.php` — vérifier que `h()`, `display_user()`, etc. ne sont plus appelés en dehors de `lib/html.php` lui-même
3. `lib/security.php` — vérifier que `csrf_field()`, etc. ne sont plus appelés en dehors de `lib/security.php` lui-même

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_admin_email()` | `App::auth()->getAdminEmail()` |

## Testing

```bash
php -l classes/migrations/schema_initial.php
```

## Vérification

Vérifier qu'aucun fichier hors `lib/auth.php`, `lib/html.php`, `lib/security.php` n'appelle encore les wrappers :

```bash
rg "get_auth_user\(|is_admin_user\(|require_admin\(|is_super_admin\(|is_admin_effective\(|is_form_owner\(|get_form_owners\(|get_owned_forms\(|get_admin_email\(|process_admin_request\(|approve_admin_request\(|reject_admin_request\(|remove_admin\(" --include="*.php" --glob="!lib/auth.php" --glob="!lib/html.php" --glob="!lib/security.php"
```

Doit retourner 0 résultat.

## Report

Écrire dans `.superpowers/sdd/task-4-report.md`
