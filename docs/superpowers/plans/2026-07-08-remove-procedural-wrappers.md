# Suppression des wrappers procéduraux — Migration DI complète

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Supprimer `lib/auth.php`, `lib/security.php`, `lib/html.php` et autres wrappers procéduraux, après avoir migré tous les appelants vers les services DI `App::*`.

**Architecture:** Chaque wrapper procédural dans `lib/` délègue aux services OOP dans `src/`. Ce plan supprime la couche procédurale en migrant tous les appelants (`lib/`, `src/`, `tests/`, `classes/`) vers les appels DI directs, puis en supprimant les wrappers.

**Tech Stack:** PHP 8.4 strict_types, DI container `App`, PHPUnit

---

## État des lieux

### Wrappers à supprimer

| Fichier | Fonctions | Utilisateurs restants |
|---------|-----------|----------------------|
| `lib/auth.php` | `get_auth_user`, `is_admin_user`, `is_super_admin`, `require_admin`, `is_admin_effective`, `is_form_owner`, `get_form_owners`, `get_owned_forms`, `get_admin_email`, `process_admin_request`, `approve_admin_request`, `reject_admin_request`, `remove_admin` | ~66 appels |
| `lib/security.php` | `csrf_field`, `verify_csrf`, `require_csrf`, `generate_csrf_token` | ~10 appels |
| `lib/html.php` | `h`, `display_user`, `display_user_short`, `format_file_size`, `get_file_icon`, `render_pagination`, `render_donut_chart`, `t_jargon` | ~50 appels |

### Mapping des remplacements

| Wrapper procédural | Remplacement DI |
|-------------------|-----------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |
| `is_super_admin()` | `App::auth()->isSuperAdmin()` |
| `require_admin()` | `App::auth()->requireAdmin()` |
| `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| `is_form_owner($id)` | `App::auth()->isFormOwner($id)` |
| `get_form_owners($id)` | `App::auth()->getFormOwners($id)` |
| `get_owned_forms()` | `App::auth()->getOwnedForms()` |
| `get_admin_email()` | `App::auth()->getAdminEmail()` |
| `process_admin_request()` | `App::auth()->processAdminRequest()` |
| `approve_admin_request()` | `App::auth()->approveAdminRequest()` |
| `reject_admin_request()` | `App::auth()->rejectAdminRequest()` |
| `remove_admin()` | `App::auth()->removeAdmin()` |
| `csrf_field()` | `App::security()->csrfField()` |
| `verify_csrf()` | `App::security()->verifyCsrf()` |
| `require_csrf()` | `App::security()->requireCsrf()` |
| `generate_csrf_token()` | `App::security()->generateCsrfToken()` |
| `h($x)` | `App::html()->escape($x)` |
| `display_user($e)` | `App::html()->displayUser($e)` |
| `display_user_short($e)` | `App::html()->displayUserShort($e)` |
| `format_file_size($b)` | `App::html()->formatFileSize($b)` |
| `get_file_icon($m)` | `App::html()->getFileIcon($m)` |
| `render_pagination(...)` | `App::html()->renderPagination(...)` |
| `render_donut_chart(...)` | `App::html()->renderDonutChart(...)` |
| `t_jargon($t)` | `App::html()->tJargon($t)` |

---

## Task 1: Migrer `lib/` — Auth wrappers

**Files:**
- Modify: `lib/render_dashboard.php`
- Modify: `lib/render_navigation.php`
- Modify: `lib/render_errors.php`
- Modify: `lib/admin_settings_handlers.php`
- Modify: `lib/admin_forms_handlers_forms.php`
- Modify: `lib/render_admin_settings.php`

- [ ] **Step 1: Migrer render_dashboard.php**

Remplacer `is_admin_effective()` par `App::auth()->isAdminEffective()`.

- [ ] **Step 2: Migrer render_navigation.php**

Remplacer `get_auth_user()`, `is_admin_user()`, `is_admin_effective()`, `get_owned_forms()`.

- [ ] **Step 3: Migrer render_errors.php**

Remplacer `get_auth_user()`.

- [ ] **Step 4: Migrer admin_settings_handlers.php**

Remplacer `get_auth_user()` (6 occurrences).

- [ ] **Step 5: Migrer admin_forms_handlers_forms.php**

Remplacer `is_form_owner()`, `is_super_admin()`.

- [ ] **Step 6: Migrer render_admin_settings.php**

Remplacer `get_admin_email()`, `get_auth_user()`.

- [ ] **Step 7: Lint + Commit**

```bash
php -l lib/render_dashboard.php lib/render_navigation.php lib/render_errors.php lib/admin_settings_handlers.php lib/admin_forms_handlers_forms.php lib/render_admin_settings.php
git add lib/
git commit -m "refactor: lib/ — auth wrappers → App::auth() DI"
```

---

## Task 2: Migrer `src/` — Auth wrappers

**Files:**
- Modify: `src/Controller/DashboardController.php`
- Modify: `src/Controller/IndexController.php`
- Modify: `src/Audit/AuditLogService.php`
- Modify: `src/Repository/AuditRepository.php`
- Modify: `src/Mail/MailerService.php`
- Modify: `src/Rgpd/RgpdService.php`
- Modify: `src/Render/HtmlService.php`

- [ ] **Step 1: Migrer DashboardController.php**

Remplacer `require_admin()`, `is_admin_user()`.

- [ ] **Step 2: Migrer IndexController.php**

Remplacer `is_admin_effective()`, `get_owned_forms()`.

- [ ] **Step 3: Migrer AuditLogService.php**

Remplacer `get_auth_user()` (2 occurrences).

- [ ] **Step 4: Migrer AuditRepository.php**

Remplacer `get_auth_user()` (2 occurrences).

- [ ] **Step 5: Migrer MailerService.php**

Remplacer `get_auth_user()`.

- [ ] **Step 6: Migrer RgpdService.php**

Remplacer `get_auth_user()`, `is_admin_user()`, `is_super_admin()` (6 occurrences).

- [ ] **Step 7: Migrer HtmlService.php**

Remplacer `get_auth_user()` dans `displayUser()`.

- [ ] **Step 8: Lint + Commit**

```bash
php -l src/Controller/DashboardController.php src/Controller/IndexController.php src/Audit/AuditLogService.php src/Repository/AuditRepository.php src/Mail/MailerService.php src/Rgpd/RgpdService.php src/Render/HtmlService.php
git add src/
git commit -m "refactor: src/ — auth wrappers → App::auth() DI"
```

---

## Task 3: Migrer `tests/` — Auth wrappers

**Files:**
- Modify: `tests/test_all.php`
- Modify: `tests/test_unit_basics.php`
- Modify: `tests/test_unit_nav_utils.php`
- Modify: `tests/test_refactor.php`
- Modify: `tests/test_unit_wave8_9.php`

- [ ] **Step 1: Migrer test_all.php**

Remplacer `get_auth_user()`, `is_admin_user()`, `is_super_admin()`, `get_admin_email()`.

- [ ] **Step 2: Migrer test_unit_basics.php**

Remplacer `get_auth_user()`, `is_admin_user()`, `is_super_admin()`, `require_admin()`, `get_admin_email()`.

- [ ] **Step 3: Migrer test_unit_nav_utils.php**

Remplacer `get_admin_email()`, `is_form_owner()`, `get_form_owners()`, `get_owned_forms()`.

- [ ] **Step 4: Migrer test_refactor.php**

Remplacer `require_admin()`, `is_admin_user()`, `is_super_admin()`.

- [ ] **Step 5: Migrer test_unit_wave8_9.php**

Remplacer `get_admin_email()`.

- [ ] **Step 6: Lint + Commit**

```bash
php -l tests/test_all.php tests/test_unit_basics.php tests/test_unit_nav_utils.php tests/test_refactor.php tests/test_unit_wave8_9.php
git add tests/
git commit -m "refactor: tests/ — auth wrappers → App::auth() DI"
```

---

## Task 4: Migrer `classes/` + `lib/html.php` + `lib/security.php`

**Files:**
- Modify: `classes/migrations/schema_initial.php`
- Modify: `lib/html.php`
- Modify: `lib/security.php`

- [ ] **Step 1: Migrer schema_initial.php**

Remplacer `get_admin_email()`.

- [ ] **Step 2: Vérifier lib/html.php**

Vérifier que `h()`, `display_user()`, etc. ne sont plus appelés en dehors de `lib/html.php` lui-même.

- [ ] **Step 3: Vérifier lib/security.php**

Vérifier que `csrf_field()`, etc. ne sont plus appelés en dehors de `lib/security.php` lui-même.

- [ ] **Step 4: Lint + Commit**

```bash
php -l classes/migrations/schema_initial.php lib/html.php lib/security.php
git add classes/ lib/html.php lib/security.php
git commit -m "refactor: classes/ + lib/ — derniers wrappers → DI"
```

---

## Task 5: Supprimer `lib/auth.php`

**Files:**
- Delete: `lib/auth.php`
- Modify: `helpers.php` (supprimer le require_once)

- [ ] **Step 1: Vérifier qu'aucun fichier n'appelle encore les wrappers**

```bash
rg "function_exists\('get_auth_user'\)|function_exists\('is_admin_user'\)" --include="*.php"
```

Doit retourner 0 résultat.

- [ ] **Step 2: Supprimer lib/auth.php**

```bash
rm lib/auth.php
```

- [ ] **Step 3: Supprimer le require_once dans helpers.php**

Supprimer la ligne `require_once __DIR__ . '/auth.php';` dans `helpers.php`.

- [ ] **Step 4: Lint + Commit**

```bash
php -l helpers.php
git add -A
git commit -m "refactor: supprimer lib/auth.php — migration DI complète"
```

---

## Task 6: Supprimer `lib/security.php`

**Files:**
- Delete: `lib/security.php`
- Modify: `helpers.php`

- [ ] **Step 1: Vérifier qu'aucun fichier n'appelle encore les wrappers**

```bash
rg "csrf_field\(|verify_csrf\(|require_csrf\(|generate_csrf_token\(" --include="*.php" --glob="!src/*" --glob="!lib/security.php"
```

Doit retourner 0 résultat.

- [ ] **Step 2: Supprimer lib/security.php**

- [ ] **Step 3: Supprimer le require_once dans helpers.php**

- [ ] **Step 4: Lint + Commit**

---

## Task 7: Supprimer `lib/html.php`

**Files:**
- Delete: `lib/html.php`
- Modify: `helpers.php`

- [ ] **Step 1: Vérifier qu'aucun fichier n'appelle encore les wrappers**

```bash
rg "\bh\(|display_user\(|display_user_short\(|format_file_size\(|get_file_icon\(|render_pagination\(|render_donut_chart\(|t_jargon\(" --include="*.php" --glob="!src/*" --glob="!lib/html.php"
```

- [ ] **Step 2: Supprimer lib/html.php**

- [ ] **Step 3: Supprimer le require_once dans helpers.php**

- [ ] **Step 4: Lint + Commit**

---

## Task 8: Nettoyage `helpers.php` + validation finale

**Files:**
- Modify: `helpers.php`
- Modify: `docs/ARCHITECTURE.md`

- [ ] **Step 1: Nettoyer helpers.php**

Supprimer tous les `require_once` des fichiers supprimés (`lib/auth.php`, `lib/security.php`, `lib/html.php`).

- [ ] **Step 2: Vérifier qu'aucun wrapper procédural n'existe plus**

```bash
rg "function (get_auth_user|is_admin_user|require_admin|csrf_field|display_user|t_jargon)\b" --include="*.php" --glob="!src/*"
```

Doit retourner 0 résultat.

- [ ] **Step 3: Tests complets**

```bash
php vendor/bin/phpstan.phar analyse
php tests/test_all.php
```

- [ ] **Step 4: Mettre à jour ARCHITECTURE.md**

Mettre à jour la section "Façade procédurale" pour refléter la suppression.

- [ ] **Step 5: Commit final**

```bash
git add -A
git commit -m "refactor: suppression complète des wrappers procéduraux — migration DI terminée"
```

---

## Notes importantes

### Risques
- **Tests** : certains tests utilisent les wrappers procéduraux pour tester le comportement. Les migrer vers DI peut casser des assertions.
- **`function_exists()`** : certains fichiers utilisent `function_exists('get_auth_user')` comme fallback. Ces checks doivent être supprimés.

### Durée estimée
- 8 tâches
- ~3-4 heures de travail
- 8 commits atomiques
