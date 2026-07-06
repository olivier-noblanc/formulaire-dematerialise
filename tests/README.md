# Tests — CircuitDémat

Ce dossier contient tous les tests du projet. Avant chaque `git push`, lance la gate complète :

```bash
bash scripts/gate.sh          # Linux / Git-for-Windows
# ou
powershell scripts/check.ps1  # Windows PowerShell
```

## Structure

```
tests/
├── test_bootstrap.php            # Framework de test minimal (fonctions test(), assert_test())
├── test_all.php                  # 51 tests PHP (DB, helpers, rendu sans erreur fatale en TEST_MODE)
├── test_form_render_html.php     # 8 tests de rendu HTML (sous-processus PHP avec TEST_MODE=false)
├── StructuralHtmlTest.php        # 15 routes × 6 règles structurelles (S1-S12)
├── helpers/
│   ├── DomAssertions.php         # Règles S1 (forms imbriqués), S3 (dates ISO), S8 (warnings), S9 (CSRF), S12 (title)
│   └── HttpClient.php            # Helper subprocess pour invoquer les routes en PHP avec TEST_MODE=false
├── regression/
│   ├── _subprocess_helper.php    # Helper partagé
│   ├── Bug01_EndifFormControllerTest.php   # FormController endif mal placé (P0)
│   ├── Bug02_UploadFailureTest.php         # Upload échec silencieux (P0)
│   ├── Bug03_NestedFormsTest.php           # Forms imbriqués admin_settings (P0)
│   ├── Bug04_ValidateExtraBraceTest.php    # validate.php extra } (P0)
│   ├── Bug05_StickyRgpdTest.php            # Checkbox RGPD non préservée (P1)
│   ├── Bug06_StickyValidateTest.php        # Motif + commentaire non préservés (P1)
│   ├── Bug07_FalseRefusedBadgeTest.php     # Faux badge Refusé (P1)
│   ├── Bug08_NoIsoDatesTest.php            # Dates ISO (P2)
│   ├── Bug09_TopbarLinkTest.php            # Topbar Nouvelle demande (P2)
│   └── run_all.php                         # Orchestrateur des 9 tests de régression
├── e2e/
│   ├── helpers.js                # Helpers Playwright (startTestServer, getCsrfToken, etc.)
│   ├── smoke.spec.js             # 5 pages publiques se chargent (15 assertions)
│   ├── admin_pages.spec.js       # 7 pages admin avec auth simulée (22 assertions)
│   ├── validation_flow.spec.js   # Render de validate.php (16 assertions)
│   ├── full_submission_flow.spec.js # Soumission complète form onboarding (21 assertions)
│   └── run_all.js                # Orchestrateur des 4 specs Playwright
├── router_test_auth.php          # Router PHP -S qui convertit HTTP_AUTH_USER → AUTH_USER
├── run_all.php                   # Orchestrateur PHP (lint + tests PHP + structurels + régression)
└── README.md                     # Ce fichier
```

## Comment lancer les tests

### Tout en une fois (gate complète avant push)

```bash
bash scripts/gate.sh
# ou
php tests/run_all.php     # PHP uniquement (pas Playwright)
# ou
node tests/e2e/run_all.js  # Playwright uniquement
```

### Par suite

```bash
# Tests PHP basiques (TEST_MODE=true, vérifient la logique métier)
php tests/test_all.php

# Tests de rendu HTML (TEST_MODE=false, vérifient le HTML produit)
php tests/test_form_render_html.php

# Tests structurels (15 routes × règles S1-S12)
php tests/StructuralHtmlTest.php

# Tests de non-régression (9 bugs historiques)
php tests/regression/run_all.php

# Tests e2e Playwright (4 scénarios complets)
node tests/e2e/run_all.js
```

## Comment fonctionne l'auth simulée

En production, l'application tourne sur IIS avec Kerberos : `$_SERVER['AUTH_USER']` est rempli par IIS.

En test (CLI ou PHP -S), on n'a pas IIS. Deux approches :

1. **TEST_MODE=true** (test_all.php) : `get_auth_user()` lit `$_SERVER['HTTP_X_TEST_USER']` à la place. Mais ce mode court-circuite le rendu HTML en JSON via `test_json_response()`. **Inutilisable pour tester le HTML rendu.**

2. **Router PHP -S avec conversion de header** (StructuralHtmlTest, e2e Playwright) :
   - Le serveur PHP -S est démarré avec `tests/router_test_auth.php` comme router
   - Le router fait `$_SERVER['AUTH_USER'] = $_SERVER['HTTP_AUTH_USER']` au début de chaque requête
   - TEST_MODE reste false → le HTML est rendu normalement
   - Playwright envoie le header `HTTP_AUTH_USER: DREETS\admin.local` (l'admin en DB de test)

Cette 2ᵉ approche permet de tester le HTML rendu avec auth admin simulée.

## Règles structurelles (S1-S12)

Appliquées par `tests/StructuralHtmlTest.php` sur toutes les routes :

| Règle | Description | Bug attrapé |
|-------|-------------|-------------|
| S1 | Aucun `<form>` descendant d'un autre `<form>` | Bug03 |
| S2 | HTML bien formé (libxml errors) | Bug01, Bug03, Bug04 |
| S3 | Aucune date ISO `\d{4}-\d{2}-\d{2}` visible | Bug08 |
| S4 | Page succès : pas de bouton submit | Bug01 |
| S5 | Page succès : pas d'encadré RGPD | Bug01 |
| S6 | Valeurs préservées après erreur (sticky) | Bug05, Bug06 |
| S8 | Aucun warning/notice PHP dans stderr | Bug04 |
| S9 | Toutes les `<form method=post>` ont un csrf_token | (hygiène) |
| S12 | `<title>` non vide | (hygiène) |

## Ajouter un test de non-régression

Quand tu corriges un bug, ajoute un test immortel dans `tests/regression/` :

1. Crée `tests/regression/BugNN_<Name>Test.php`
2. Définis une fonction `run_bugNN_test(): bool`
3. Ajoute-le à `tests/regression/run_all.php` (require_once + appel)
4. Le test ne sera JAMAIS supprimé — il documente le piège.

## Ajouter un test e2e Playwright

1. Crée `tests/e2e/<scenario>.spec.js`
2. Requiers `tests/e2e/helpers.js`
3. Utilise `startTestServer()` pour démarrer PHP -S avec router auth
4. Ajoute-le à `tests/e2e/run_all.js`

## Dépendances

- PHP 8.4 (avec mbstring, pdo_sqlite)
- Node.js 18+ et Playwright (`npm install -g playwright` ou via `node_modules`)
- Chromium pour Playwright (`playwright install chromium`)

## Métriques actuelles

| Suite | Tests | Durée |
|-------|-------|-------|
| test_all.php | 51 | ~60s |
| test_form_render_html.php | 8 | ~30s |
| StructuralHtmlTest.php | 15 routes × 6 règles = 90 assertions | ~15s |
| regression/run_all.php | 9 | ~5s |
| e2e/run_all.js | 4 specs × ~18 assertions = 74 | ~10s |
| **Total** | **~250 assertions** | **~2 min** |

## Hook pre-push

Pour activer le hook git qui bloque le push si la gate échoue :

```bash
bash scripts/install_hooks.sh
```

Le hook est installé dans `.git/hooks/pre-push`. Il exécute `scripts/gate.sh` avant tout push vers `master` ou `dev`. Pour bypasser (déconseillé) : `git push --no-verify`.
