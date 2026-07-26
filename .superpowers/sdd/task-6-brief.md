# Task 6: Supprimer `lib/security.php`

## Context

Les wrappers de sécurité (`csrf_field()`, `verify_csrf()`, etc.) doivent être remplacés par des appels DI directs.

## Fichiers à modifier

1. `lib/security.php` — SUPPRIMER
2. `helpers.php` — supprimer le `require_once __DIR__ . '/security.php';`

## Vérification préalable

```bash
rg "\bcsrf_field\(|verify_csrf\(|require_csrf\(|generate_csrf_token\(" --glob="*.php" --glob="!lib/security.php" --glob="!src/*"
```

Doit retourner 0 résultat (hors src/ qui a déjà été migré).

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `csrf_field()` | `App::security()->csrfField()` |
| `verify_csrf()` | `App::security()->verifyCsrf()` |
| `require_csrf()` | `App::security()->requireCsrf()` |
| `generate_csrf_token()` | `App::security()->generateCsrfToken()` |

## Testing

```bash
php -l helpers.php
```

## Report

Écrire dans `.superpowers/sdd/task-6-report.md`
