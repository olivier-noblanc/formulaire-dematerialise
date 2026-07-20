# CI — Intégration Continue pour CircuitDémat

> CI GitHub Actions — `.github/workflows/ci.yml`

## Architecture

4 jobs, exécutés sur `ubuntu-latest` avec PHP 8.5 :

| Job | Dépend de | Contenu |
|-----|-----------|---------|
| **Lint PHP** | — | Vérifie la syntaxe de tous les fichiers PHP (`php -l`) |
| **PHPStan (level 8)** | — | Analyse statique stricte avec baseline |
| **PHPUnit (unit + e2e)** | — | 1285+ tests unitaires et e2e |
| **Tests fonctionnels** | phpstan, phpunit | test_all.php, test_mail_escaping, test_email_urls, test_assets_cache |

Les 3 premiers jobs tournent en parallèle. Les tests fonctionnels attendent leur succès.

## Déclencheurs

- **push** sur `master`
- **pull request** vers `master`

## Environnement CI

- PHP 8.5 (shivammathur/setup-php)
- Extensions : `pdo_sqlite`, `sqlite3`
- `config.php` : stub minimal généré en CI (pas de secrets, pas de SMTP)
- `vendor/` : installé via `composer install` (pas le vendor commité)
- `db/` : créé vide, les tests créent leur propre DB de test

## Gate locale (check.ps1)

Le script `scripts/check.ps1` est le miroir Windows de la gate CI :

1. Lint PHP (fichiers modifiés uniquement)
2. PHPStan + PHPUnit en parallèle
3. Tests fonctionnels (test_all.php, test_mail_escaping, etc.)
4. Tests e2e Playwright (optionnel)

```powershell
.\scripts\check.ps1
```

## Déploiement (update.ps1)

Le deploy sur IIS prod utilise `update.ps1` qui :
1. Télécharge la dernière version depuis GitHub
2. Lance lint + tests fonctionnels (pas PHPStan — outil dev)
3. Rollback automatique si la gate échoue

```powershell
.\update.ps1
```

## Notes techniques

- `vendor/shipmonk/dead-code-detector/rules.neon` n'est **pas** dans le repo (gitignore). `composer install` le restaure en CI.
- Les 2 tests "8 vs 18 forms" sont pré-existants (DB de test contient 18 forms, les tests en attendent 8).
