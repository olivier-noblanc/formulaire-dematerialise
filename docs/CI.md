# CI — Intégration Continue pour CircuitDémat

> Ce document explique le fonctionnement de la CI sur le dépôt Codeberg.

## Les 2 solutions CI en parallèle

Le dépôt dispose de **deux** configurations CI qui subsistent en parallèle :

| Solution | Fichier | Statut |
|----------|---------|--------|
| **Forgejo Actions** | `.forgejo/workflows/ci.yml` | ✅ Utilise les runners **publics Codeberg** |
| **Woodpecker CI** | `.woodpecker.yml` | ⚠️ Nécessite un runner Woodpecker auto-hébergé |

## Runner public Codeberg (recommandé — zéro install)

Contrairement à GitHub, **Codeberg ne fournit pas de runners managés type
"GitHub-hosted"**. En revanche, l'association Codeberg met à disposition des
**runners publics globaux** que n'importe quel dépôt public peut utiliser
gratuitement, sans inscription ni token.

Source officielle : https://codeberg.org/actions/meta

### Labels disponibles

| Label | CPU | RAM | Durée max |
|-------|-----|-----|-----------|
| `codeberg-tiny` | 1 | 2 Go | 2 min |
| `codeberg-small` | 2 | 4 Go | 5 min |
| `codeberg-medium` | 4 | 8 Go | 10 min |

Le workflow `ci.yml` utilise `codeberg-small` (5 min — largement suffisant
pour lint + PHPStan + tests).

### Conditions d'utilisation

1. ⚠️ **Le dépôt doit être PUBLIC** — les runners publics ignorent les
   dépôts privés. Vérifie sur Codeberg → Settings → General → Repository
   visibility.
2. Ne pas saturer la queue (fair use)
3. Pas de Docker-in-Docker (pas de `docker build`, `docker compose`)

### Activation (1 action manuelle)

1. **Vérifier que le dépôt est public** :
   - Va sur https://codeberg.org/oliviernoblanc/formulaire-dematerialise
     dans une fenêtre **incognito**
   - Si tu vois le code → il est public ✅
   - Si tu vois "Not found" ou une page de login → il est privé ❌
   - Pour le rendre public : Settings → General → Repository visibility
     → "Change visibility" → Public

2. **Activer Actions sur le dépôt** :
   - Settings → Units → cocher "Actions"
   - Cela fait apparaître l'onglet "Actions" dans le dépôt

3. **C'est tout** — le workflow `.forgejo/workflows/ci.yml` est déjà
   configuré avec `runs-on: codeberg-small`. Au prochain push, le job
   sera automatiquement pris en charge par un runner public Codeberg.
   Aucune approbation manuelle nécessaire.

### Vérifier le statut des jobs

- URL : https://codeberg.org/oliviernoblanc/formulaire-dematerialise/actions
- Chaque push crée un "run" avec les 9 étapes (install PHP, lint, phpstan,
  tests, render_html, regression, audit_urls, ui_audit)
- Si une étape échoue, le job s'arrête (fail-fast)

## Workflow CI (9 étapes en 1 job)

Le fichier `.forgejo/workflows/ci.yml` exécute **un seul job** sur
`codeberg-small` pour minimiser le temps total (~3-4 min). Toutes les
étapes sont en séquence avec fail-fast (si l'une échoue, le job
s'arrête).

| # | Étape | Description | Durée ~ |
|---|-------|-------------|---------|
| 1 | Checkout | Récupère le code | 5s |
| 2 | Install PHP 8.4 | apt install depuis sury.org + extensions | 60-90s |
| 3 | Lint PHP | `php -l` sur tous les fichiers `.php` | 5s |
| 4 | PHPStan | Analyse statique niveau 6 (baseline autorisée) | 30-60s |
| 5 | Tests | `test_all.php` (57 tests fonctionnels) | 10s |
| 6 | Render HTML | `test_form_render_html.php` | 15s |
| 7 | Regression | `tests/regression/run_all.php` (11 bugs historiques) | 15s |
| 8 | Audit URLs | `test_no_broken_urls.php` (9 catégories) | 15s |
| 9 | UI audit | `test_no_topbar_breadcrumb.php` (épuration UI) | 15s |

**Total** : ~3-4 minutes (sous le timeout de 5 min de `codeberg-small`).

### Pourquoi un seul job (pas 7 jobs en parallèle) ?

1. Chaque job démarre un nouveau container → ~30s de setup
2. 7 jobs × 30s = 3.5 min juste pour les setups
3. Avec un seul job, on installe PHP 1 fois (60s) et on enchaîne les étapes
4. Le fail-fast est préservé via `set -e` (défaut bash)

### Pourquoi ne pas utiliser `container: php:8.4-cli` ?

Les runners Codeberg **ne supportent pas officiellement Docker-in-Docker**.
L'option `container:` peut marcher (le runner utilise podman en arrière-plan)
mais ce n'est pas garanti. L'approche robuste : utiliser l'image ubuntu par
défaut du runner et installer PHP 8.4 via apt depuis sury.org.

## Configuration locale (développement)

Pour exécuter la même gate qualité localement avant de push :

```bash
# 1. Lint PHP
find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" -print0 \
  | xargs -0 -n1 php -l

# 2. PHPStan (nécessite PHPStan 2.x — voir composer.json)
composer install
vendor/bin/phpstan analyse --memory-limit=512M --no-progress

# 3. Tests
php tests/test_all.php
php tests/regression/run_all.php
php tests/test_no_broken_urls.php
php tests/test_no_topbar_breadcrumb.php
```

## Exigence PHPStan 2.x

Le `phpstan-baseline.neon` a été régénéré avec **PHPStan 2.x**. PHPStan
1.x peut signaler des erreurs "No error to ignore is reported" sur les
annotations `@phpstan-ignore-next-line` (car 1.x ne détecte pas les
erreurs `function.alreadyNarrowedType` que 2.x détecte).

Pour éviter ce problème :
- `composer.json` déclare `"phpstan/phpstan": "^2.0"` dans `require-dev`
- Quand tu fais `composer install`, tu obtiens PHPStan 2.x automatiquement
- Si tu utilises un phar téléchargé manuellement, vérifie la version :
  ```bash
  php phpstan.phar --version  # doit afficher 2.x
  ```
- Le workflow CI télécharge la dernière version de PHPStan (`latest`)
  → donc 2.x automatiquement

## Runner Woodpecker (alternative)

Si tu préfères Woodpecker CI, voir https://woodpecker-ci.org/docs/administration/installation.
Tu devras installer un runner Woodpecker auto-hébergé (les runners publics
Woodpecker nécessitent une approbation manuelle, contrairement aux runners
publics Forgejo Actions qui sont automatiques).

## Dépannage

### Le job reste "Pending" indéfiniment

- Vérifie que le dépôt est **public** (fenêtre incognito)
- Vérifie que Actions est activé dans Settings → Units
- Si le label `codeberg-small` n'existe pas, les runners sont peut-être
  en maintenance — voir https://codeberg.org/actions/meta pour le statut

### Le job échoue avec "packages.sury.org unreachable"

- Le réseau du runner a temporairement échoué
- Relance le job manuellement depuis l'onglet Actions

### Le job dépasse les 5 minutes

- Passe sur `codeberg-medium` (10 min max) en modifiant `runs-on:` dans
  `.forgejo/workflows/ci.yml`
- Ou coupe des étapes moins critiques (render_html, audit_urls) pour
  réduire le temps total

## Sécurité

- **Ne committez jamais un token runner** dans le repo
- Les runners publics Codeberg n'ont pas besoin de token — c'est la
  principale différence avec un runner auto-hébergé
- Les secrets du dépôt (Settings → Secrets) sont accessibles aux jobs,
  mais le repo est public → ne mets JAMAIS de secret dedans
