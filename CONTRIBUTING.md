# CONTRIBUTING — CircuitDémat

Ce document décrit le workflow de développement pour le projet CircuitDémat. **Lis-le avant ton premier commit.**

## Workflow de branches

- `master` : branche de production. **Protégée** — pas de push direct.
- `dev` : branche d'intégration quotidienne. Le dev pousse sur `dev`.
- Pour merger `dev` → `master` : fast-forward uniquement, après gate verte.

## Avant chaque push : la gate

Le hook `pre-push` (à installer une fois avec `bash scripts/install_hooks.sh`) exécute automatiquement `scripts/gate.sh` avant tout push vers `master` ou `dev`.

La gate contient :
1. Lint PHP (`php -l` sur fichiers modifiés)
2. `tests/test_all.php` (51 tests)
3. `tests/test_form_render_html.php` (8 tests rendu HTML)
4. `tests/StructuralHtmlTest.php` (15 routes × règles S1-S12)
5. `tests/regression/run_all.php` (9 tests de non-régression)
6. Tests e2e Playwright (4 scénarios)

Si un seul test échoue → **push bloqué**. Corrige le problème.

Pour bypasser (cas extrême, déconseillé) : `git push --no-verify`. À justifier en commit message.

## Convention de commit

Format : `type: description courte`

Types :
- `fix:` correction de bug
- `feat:` nouvelle fonctionnalité
- `refactor:` refactor sans changement de comportement
- `test:` ajout/modification de tests
- `docs:` documentation
- `chore:` tâches diverses (config, dépendances)

Exemples :
```
fix: encadré RGPD qui fuyait sur la page succès du formulaire
feat: journal des emails dans monitoring.php
test: 9 tests de non-régression pour les bugs historiques
docs: README des tests et CONTRIBUTING
```

## Quand tu corriges un bug

1. **Reproduis le bug** dans un test (nouveau fichier dans `tests/regression/BugNN_*.php`).
2. **Corrige le code source**.
3. **Vérifie que le test passe** : `php tests/regression/run_all.php`.
4. **Lance la gate complète** : `bash scripts/gate.sh`.
5. **Commit** avec message `fix: ...`.
6. **Met à jour CHANGELOG.md** avec une section décrivant le bug (symptôme, cause, fix, fichiers).

## Quand tu ajoutes une fonctionnalité

1. **Écris les tests d'abord** (TDD léger) :
   - Test unitaire dans `tests/test_all.php` si logique pure
   - Test structurel dans `tests/StructuralHtmlTest.php` si nouvelle route
   - Test e2e dans `tests/e2e/` si flow utilisateur
2. **Implémente la fonctionnalité**.
3. **Vérifie la gate**.
4. **Commit** avec message `feat: ...`.
5. **Met à jour CHANGELOG.md** et `tests/README.md` si nécessaire.

## Règles de code

### PHP
- `declare(strict_types=1);` en haut de chaque fichier
- PSR-12 (mais on n'a pas de linter automatique — soit discipliné)
- Pas de variable undefined : utilise `??` partout
- Pas de date ISO dans le HTML visible : utilise `date('d/m/Y à H:i', strtotime(...))`
- Pas de `<form>` imbriqué (HTML invalide)
- Tous les `<form method=post>` doivent avoir un `<input name="csrf_token">`

### JavaScript
- ES6+ (async/await)
- Pas de framework (vanilla JS ou Playwright)
- Pas de variable globale (sauf `_test_mails` en mode test)

### Tests
- Les tests de non-régression sont **immortels** — on ne les supprime jamais
- Un test doit échouer si le bug réapparaît
- Un test ne doit pas dépendre de l'état de la DB (sauf si seed explicite)

## Gestion des warnings PHP

**Tout warning/notice PHP est un échec de test.** La règle S8 dans `tests/StructuralHtmlTest.php` capture le stderr et fait échouer le test si un warning est détecté.

Exceptions (filtrées automatiquement) :
- `session.use_only_cookies` (PHP 8.4 en CLI)
- `PHP Request Shutdown`
- `Session cache limiter cannot be sent`

Si tu rencontres un nouveau faux positif, ajoute-le au filtre dans `tests/helpers/DomAssertions.php::assertNoPhpWarnings()`.

## Ajouter un formulaire de test

Les formulaires de test (onboarding, acces_si, etc.) sont dans la DB de production. Pour les tests e2e, on utilise directement ces formulaires avec l'utilisateur admin `olivier.noblanc@dreets.gouv.fr` (déjà en DB).

Ne crée PAS de formulaire de test dédié dans la DB — ça polluerait la prod. Les tests e2e nettoient leurs soumissions après exécution (`DELETE FROM submissions WHERE submitted_by LIKE 'test-%'`).

## Mise à jour du CHANGELOG

Le `CHANGELOG.md` est la source de vérité pour la version (lue par `get_latest_version()`). Format :

```markdown
## [X.Y.Z] — YYYY-MM-DD
_Résumé : une phrase._

### Type de changement

- **Symptôme** : ...
- **Cause** : ...
- **Fix** : ...
- **Fichiers** : `chemin/fichier.php`
```

Types de changement :
- 🔴 Bug critique (P0)
- ⚠️ Bug (P1)
- 📅 Bug (P2)
- 🧭 Navigation
- ♿ Accessibilité
- 📧 Email/SMTP
- 🧪 Tests
- 🔍 Audit
- ✨ Nouvelle fonctionnalité

## En cas de doute

- **Tu ne sais pas si ton changement casse quelque chose ?** Lance `bash scripts/gate.sh`.
- **Tu veux tester en conditions réelles ?** Démarre `php -S 127.0.0.1:8899 tests/router_test_auth.php` et navigue avec le header `AUTH_USER: DREETS\olivier.noblanc`.
- **Tu as trouvé un bug en prod ?** Crée un test de non-régression d'abord, puis corrige.
