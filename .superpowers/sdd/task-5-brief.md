# Task 5: Supprimer `lib/auth.php`

## Context

Tous les appelants de `lib/auth.php` ont été migrés vers les appels DI directs. Il est maintenant sûr de supprimer le fichier.

## Fichiers à modifier

1. `lib/auth.php` — SUPPRIMER
2. `helpers.php` — supprimer le `require_once __DIR__ . '/auth.php';`

## Vérification préalable

Avant de supprimer, vérifier qu'aucun fichier n'appelle encore les wrappers :

```bash
rg "function_exists\('get_auth_user'\)|function_exists\('is_admin_user'\)" --include="*.php"
```

Doit retourner 0 résultat.

```bash
rg "\bget_auth_user\(|is_admin_user\(|is_super_admin\(|require_admin\(|is_admin_effective\(|is_form_owner\(|get_form_owners\(|get_owned_forms\(|get_admin_email\(|process_admin_request\(|approve_admin_request\(|reject_admin_request\(|remove_admin\(" --include="*.php" --glob="!lib/auth.php"
```

Doit retourner 0 résultat.

## Testing

```bash
php -l helpers.php
```

## Report

Écrire dans `.superpowers/sdd/task-5-report.md`
