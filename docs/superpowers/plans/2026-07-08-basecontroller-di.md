# Refactoring DI — Pages procédurales → App:: services

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer tous les appels aux wrappers procéduraux (`is_admin_user()`, `get_auth_user()`, `require_admin()`, `_dbm_q()`, etc.) dans `pages/` par des appels directs aux services DI (`App::auth()`, `App::db()`, etc.).

**Architecture:** Chaque page `pages/xxx.php` appelle encore des fonctions procédurales (`lib/auth.php`, `lib/security.php`, etc.) qui délèguent aux services OOP. Ce refactoring supprime la couche procédurale dans les pages et appelle directement les services. Les wrappers procéduraux restent dans `lib/` pour la rétrocompatibilité des fichiers hors `pages/`.

**Tech Stack:** PHP 8.4 strict_types, DI container `App`, repositories

---

## État des lieux

### Fichiers propres (aucun changement nécessaire)
- `pages/accueil.php` — délègue à `IndexController`
- `pages/dashboard.php` — délègue à `DashboardController`
- `pages/form.php` — délègue à `FormController`

### Fichiers à refactoiser (22 fichiers)

| Priorité | Fichier | Appels procéduraux | Impact |
|----------|---------|-------------------|--------|
| P0 | `admin_access.php` | 14 auth calls | Élevé — page admin critique |
| P0 | `form_tracking.php` | 9 calls | Élevé — page suivi |
| P0 | `backup.php` | 5 calls + `new PDO()` | Élevé — page sensible |
| P1 | `monitoring.php` | 4 calls | Moyen |
| P1 | `admin_alerts.php` | 3 calls | Moyen |
| P1 | `rgpd.php` | 6 calls | Moyen |
| P1 | `download.php` | 4 calls | Moyen |
| P1 | `submission_view.php` | 4 calls | Moyen |
| P1 | `stats.php` | 8 calls | Moyen |
| P2 | `my_submissions.php` | 6 calls | Faible — déjà partiellement DI |
| P2 | `my_validations.php` | 4 calls | Faible |
| P2 | `validate.php` | 4 calls | Faible |
| P2 | `form_preview.php` | 4 calls | Faible |
| P2 | `docs.php` | 4 calls | Faible |
| P2 | `changelog.php` | 3 calls | Faible |
| P2 | `confirm_action.php` | 5 calls | Faible |
| P2 | `my_forms.php` | 4 calls | Faible |
| P2 | `persona.php` | 4 calls | Faible |
| P3 | `admin_forms.php` | 4 calls | Faible — déjà partiellement DI |
| P3 | `admin_settings.php` | 2 calls | Faible |
| P3 | `health.php` | 5 calls | Faible |
| P3 | `screenshot.php` | 1 call | Faible |

### Mapping des remplacements

| Wrapper procédural | Remplacement DI |
|-------------------|-----------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_user()` | `App::auth()->isAdmin()` |
| `is_super_admin()` | `App::auth()->isSuperAdmin()` |
| `require_admin()` | `App::auth()->requireAdmin()` |
| `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| `is_form_owner($form_id)` | `App::auth()->isFormOwner($form_id)` |
| `get_form_owners($form_id)` | `App::auth()->getFormOwners($form_id)` |
| `get_owned_forms()` | `App::auth()->getOwnedForms()` |
| `get_admin_email()` | `App::auth()->getAdminEmail()` |
| `process_admin_request()` | `App::auth()->processAdminRequest()` |
| `approve_admin_request($id)` | `App::auth()->approveAdminRequest($id)` |
| `reject_admin_request($id)` | `App::auth()->rejectAdminRequest($id)` |
| `remove_admin($email)` | `App::auth()->removeAdmin($email)` |
| `csrf_field()` | `App::security()->csrfField()` |
| `display_user($email)` | `App::html()->displayUser($email)` |
| `get_file_icon($mime)` | `App::html()->getFileIcon($mime)` |
| `format_file_size($bytes)` | `App::html()->formatFileSize($bytes)` |
| `render_donut_chart(...)` | `App::html()->renderDonutChart(...)` |
| `render_pagination(...)` | `App::html()->renderPagination(...)` |
| `t_jargon($text)` | `App::html()->tJargon($text)` |
| `get_latest_version()` | `App::cache()->getLatestVersion()` |

### Note sur `_dbm_q()`

`_dbm_q()` est un helper SQL dans `DatabaseMigrations.php` qui n'a pas d'équivalent DI. Les appels `_dbm_q()` dans les pages devront être remplacés par des appels `App::db()->getPdo()->prepare(...)` avec prepared statements. Ce refactoring est inclus dans les tâches P0-P1.

---

## Task 1: admin_access.php (14 appels procéduraux)

**Files:**
- Modify: `pages/admin_access.php`

**Interfaces:**
- Consumes: `App::auth()`, `App::security()`, `App::audit()`
- Produces: Aucun changement d'interface externe

- [ ] **Step 1: Remplacer les appels auth**

Remplacer chaque appel procédural par son équivalent DI :

```php
// AVANT
get_auth_user()
// APRÈS
App::auth()->getUser()

// AVANT
is_super_admin()
// APRÈS
App::auth()->isSuperAdmin()

// AVANT
is_admin_user()
// APRÈS
App::auth()->isAdmin()

// AVANT
process_admin_request()
// APRÈS
App::auth()->processAdminRequest()

// AVANT
approve_admin_request($request_id)
// APRÈS
App::auth()->approveAdminRequest($request_id)

// AVANT
reject_admin_request($request_id)
// APRÈS
App::auth()->rejectAdminRequest($request_id)

// AVANT
remove_admin($email)
// APRÈS
App::auth()->removeAdmin($email)

// AVANT
get_admin_email()
// APRÈS
App::auth()->getAdminEmail()
```

- [ ] **Step 2: Remplacer les appels security/audit**

```php
// AVANT
csrf_field()
// APRÈS
App::security()->csrfField()

// AVANT
app_log(...)
// APRÈS
App::audit()->log(...)
```

- [ ] **Step 3: Supprimer les use statements inutiles**

Supprimer les `use` des classes déjà accessibles via `App::` si plus nécessaires.

- [ ] **Step 4: Vérifier que la page fonctionne**

```bash
php -l pages/admin_access.php
```

- [ ] **Step 5: Commit**

```bash
git add pages/admin_access.php
git commit -refactor: admin_access.php — auth wrappers → App::auth() DI"
```

---

## Task 2: form_tracking.php (9 appels procéduraux)

**Files:**
- Modify: `pages/form_tracking.php`

- [ ] **Step 1: Remplacer les appels auth**

```php
// Remplacer :
get_auth_user()     → App::auth()->getUser()
is_admin_user()     → App::auth()->isAdmin()
is_super_admin()    → App::auth()->isSuperAdmin()
is_form_owner(...)  → App::auth()->isFormOwner(...)
get_form_owners(...)→ App::auth()->getFormOwners(...)
```

- [ ] **Step 2: Remplacer display_user**

```php
display_user($email) → App::html()->displayUser($email)
```

- [ ] **Step 3: Remplacer render_pagination**

```php
render_pagination(...) → App::html()->renderPagination(...)
```

- [ ] **Step 4: Vérifier + Commit**

```bash
php -l pages/form_tracking.php
git add pages/form_tracking.php
git commit -m "refactor: form_tracking.php — auth/HTML wrappers → DI"
```

---

## Task 3: backup.php (5 calls + new PDO)

**Files:**
- Modify: `pages/backup.php`

- [ ] **Step 1: Remplacer require_admin**

```php
require_admin() → App::auth()->requireAdmin()
```

- [ ] **Step 2: Remplacer _dbm_q()**

Remplacer chaque `_dbm_q($pdo, "SQL")` par un prepared statement via `App::db()->getPdo()` :

```php
// AVANT
$pdo = App::db()->getPdo();
$count = _dbm_q($pdo, "SELECT COUNT(*) FROM submissions")->fetchColumn();

// APRÈS
$pdo = App::db()->getPdo();
$count = $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
```

Note : `_dbm_q()` est juste un wrapper qui fait `$pdo->query($sql)` avec un check `false`. Pour les requêtes simples sans paramètres, utiliser directement `$pdo->query()` est sûr.

- [ ] **Step 3: Remplacer get_db_size**

```php
get_db_size() → App::cache()->getDbSize()
// ou si pas de méthode dédiée, garder le code inline
```

- [ ] **Step 4: Vérifier + Commit**

```bash
php -l pages/backup.php
git add pages/backup.php
git commit -m "refactor: backup.php — procedural wrappers → DI"
```

---

## Task 4: monitoring.php (4 appels procéduraux)

**Files:**
- Modify: `pages/monitoring.php`

- [ ] **Step 1: Remplacer require_admin + get_auth_user**

```php
require_admin()    → App::auth()->requireAdmin()
get_auth_user()    → App::auth()->getUser()
```

- [ ] **Step 2: Remplacer _dbm_q() (5 calls)**

Remplacer chaque `_dbm_q($pdo, "SQL")` par `$pdo->query("SQL")`.

- [ ] **Step 3: Vérifier + Commit**

```bash
php -l pages/monitoring.php
git add pages/monitoring.php
git commit -m "refactor: monitoring.php — procedural wrappers → DI"
```

---

## Task 5: admin_alerts.php (3 appels procéduraux)

**Files:**
- Modify: `pages/admin_alerts.php`

- [ ] **Step 1: Remplacer require_admin + csrf_field**

```php
require_admin() → App::auth()->requireAdmin()
csrf_field()    → App::security()->csrfField()
```

- [ ] **Step 2: Remplacer _dbm_q() (3 calls)**

- [ ] **Step 3: Vérifier + Commit**

```bash
php -l pages/admin_alerts.php
git add pages/admin_alerts.php
git commit -m "refactor: admin_alerts.php — procedural wrappers → DI"
```

---

## Task 6: rgpd.php (6 appels procéduraux)

**Files:**
- Modify: `pages/rgpd.php`

- [ ] **Step 1: Remplacer require_admin + get_auth_user**

```php
require_admin() → App::auth()->requireAdmin()
get_auth_user() → App::auth()->getUser()
```

- [ ] **Step 2: Remplacer _dbm_q() (3 calls) + get_db_size + format_file_size**

```php
get_db_size()                → (inline ou nouveau helper)
format_file_size($bytes)     → App::html()->formatFileSize($bytes)
```

- [ ] **Step 3: Vérifier + Commit**

```bash
php -l pages/rgpd.php
git add pages/rgpd.php
git commit -m "refactor: rgpd.php — procedural wrappers → DI"
```

---

## Task 7: download.php (4 appels procéduraux)

**Files:**
- Modify: `pages/download.php`

- [ ] **Step 1: Remplacer get_auth_user + is_admin_user**

```php
get_auth_user()  → App::auth()->getUser()
is_admin_user()  → App::auth()->isAdmin()
```

- [ ] **Step 2: Vérifier + Commit**

```bash
php -l pages/download.php
git add pages/download.php
git commit -m "refactor: download.php — auth wrappers → DI"
```

---

## Task 8: submission_view.php (4 appels procéduraux)

**Files:**
- Modify: `pages/submission_view.php`

- [ ] **Step 1: Remplacer auth wrappers**

```php
get_auth_user()      → App::auth()->getUser()
is_admin_effective() → App::auth()->isAdminEffective()
is_form_owner(...)   → App::auth()->isFormOwner(...)
```

- [ ] **Step 2: Vérifier + Commit**

```bash
php -l pages/submission_view.php
git add pages/submission_view.php
git commit -m "refactor: submission_view.php — auth wrappers → DI"
```

---

## Task 9: stats.php (8 appels procéduraux)

**Files:**
- Modify: `pages/stats.php`

- [ ] **Step 1: Remplacer require_admin + display_user**

```php
require_admin()        → App::auth()->requireAdmin()
display_user($email)   → App::html()->displayUser($email)
render_donut_chart(...) → App::html()->renderDonutChart(...)
format_file_size(...)  → App::html()->formatFileSize(...)
```

- [ ] **Step 2: Remplacer _dbm_q() (2 calls)**

- [ ] **Step 3: Vérifier + Commit**

```bash
php -l pages/stats.php
git add pages/stats.php
git commit -m "refactor: stats.php — procedural wrappers → DI"
```

---

## Task 10: Batch P2 — 9 fichiers restants

**Files:**
- Modify: `pages/my_submissions.php`
- Modify: `pages/my_validations.php`
- Modify: `pages/validate.php`
- Modify: `pages/form_preview.php`
- Modify: `pages/docs.php`
- Modify: `pages/changelog.php`
- Modify: `pages/confirm_action.php`
- Modify: `pages/my_forms.php`
- Modify: `pages/persona.php`

- [ ] **Step 1: my_submissions.php**

```php
get_auth_user()    → App::auth()->getUser()
display_user(...)  → App::html()->displayUser(...)
t_jargon(...)      → App::html()->tJargon(...)
```

- [ ] **Step 2: my_validations.php**

```php
get_auth_user() → App::auth()->getUser()
t_jargon(...)   → App::html()->tJargon(...)
```

- [ ] **Step 3: validate.php**

```php
get_auth_user()     → App::auth()->getUser()
t_jargon(...)       → App::html()->tJargon(...)
get_file_icon(...)  → App::html()->getFileIcon(...)
format_file_size(...) → App::html()->formatFileSize(...)
```

- [ ] **Step 4: form_preview.php**

```php
require_admin() → App::auth()->requireAdmin()
get_auth_user() → App::auth()->getUser()
```

- [ ] **Step 5: docs.php**

```php
get_auth_user()       → App::auth()->getUser()
is_admin_effective()  → App::auth()->isAdminEffective()
get_latest_version()  → App::cache()->getLatestVersion()
```

- [ ] **Step 6: changelog.php**

```php
get_latest_version() → App::cache()->getLatestVersion()
t_jargon(...)        → App::html()->tJargon(...)
```

- [ ] **Step 7: confirm_action.php**

```php
display_user(...) → App::html()->displayUser(...)
```

- [ ] **Step 8: my_forms.php**

```php
get_auth_user()    → App::auth()->getUser()
get_owned_forms()  → App::auth()->getOwnedForms()
t_jargon(...)      → App::html()->tJargon(...)
```

- [ ] **Step 9: persona.php**

```php
require_admin() → App::auth()->requireAdmin()
get_auth_user() → App::auth()->getUser()
```

- [ ] **Step 10: Vérifier tous les fichiers + Commit**

```bash
php -l pages/my_submissions.php
php -l pages/my_validations.php
php -l pages/validate.php
php -l pages/form_preview.php
php -l pages/docs.php
php -l pages/changelog.php
php -l pages/confirm_action.php
php -l pages/my_forms.php
php -l pages/persona.php

git add pages/
git commit -m "refactor: batch P2 — 9 pages procedural → DI"
```

---

## Task 11: Batch P3 — 3 fichiers restants

**Files:**
- Modify: `pages/admin_forms.php`
- Modify: `pages/admin_settings.php`
- Modify: `pages/health.php`
- Modify: `pages/screenshot.php`

- [ ] **Step 1: admin_forms.php**

```php
require_admin()        → App::auth()->requireAdmin()
get_form_owners(...)   → App::auth()->getFormOwners(...)
```

- [ ] **Step 2: admin_settings.php**

```php
require_admin() → App::auth()->requireAdmin()
```

- [ ] **Step 3: health.php**

```php
get_latest_version() → App::cache()->getLatestVersion()
```

- [ ] **Step 4: screenshot.php**

```php
get_auth_user() → App::auth()->getUser()
```

- [ ] **Step 5: Vérifier + Commit**

```bash
php -l pages/admin_forms.php
php -l pages/admin_settings.php
php -l pages/health.php
php -l pages/screenshot.php

git add pages/
git commit -m "refactor: batch P3 — 4 pages procedural → DI"
```

---

## Task 12: Tests et validation

**Files:**
- Modify: `tests/` (si nécessaire)

- [ ] **Step 1: Lint complet**

```bash
php -l pages/*.php
```

Tous les fichiers doivent retourner "No syntax errors".

- [ ] **Step 2: PHPStan**

```bash
php vendor/bin/phpstan.phar analyse
```

Doit retourner 0 erreur.

- [ ] **Step 3: Tests PHPUnit**

```bash
php tests/test_all.php
```

Tous les tests doivent passer.

- [ ] **Step 4: Gate qualité**

```bash
bash scripts/gate.sh
```

10/10 étapes doivent passer.

- [ ] **Step 5: Commit final**

```bash
git add -A
git commit -m "refactor: DI migration complète — 22 pages procédurales → App:: services"
```

---

## Notes importantes

### Ce qui NE change PAS
- `helpers.php` — la façade procédurale reste pour la rétrocompatibilité
- `lib/auth.php`, `lib/security.php`, etc. — les wrappers restent pour les fichiers hors `pages/`
- `index.php` — le front controller reste identique
- `src/` — aucun changement dans les services/repositories

### Risques
- **Bug silencieux** : un appel remplacé incorrectement pourrait casser une page. Chaque fichier doit être linté ET testé.
- **_dbm_q()** : le remplacement par `$pdo->query()` est généralement sûr, mais certaines requêtes utilisent `_dbm_q()` pour sa gestion d'erreur. Vérifier chaque cas.

### Durée estimée
- 12 tâches
- ~2-3 heures de travail
- 12 commits atomiques
