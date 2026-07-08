# Architecture technique — CircuitDémat

> Guide de référence pour comprendre le code, ajouter un service, ou corriger un bug sans casser l'ensemble.

---

## Vue d'ensemble

```
CircuitDémat/
├── index.php              # Front controller (seul point d'entrée)
├── helpers.php            # Façade procédurale (83 lignes) → délègue aux services
├── config.php             # Configuration locale (DB_PATH, BASE_URL, SETTINGS_DEFAULTS)
├── assets.php             # Serveur CSS/JS avec cache HTTP
├── pages/                 # Pages métier (route par ?p=xxx)
├── lib/                   # Modules procéduraux (render, admin_forms, etc.)
├── classes/               # DatabaseMigrations + migrations v10-v26
├── src/                   # Architecture OOP (services, repositories, controllers)
├── tests/                 # PHPUnit + Playwright
├── vendor/                # Composer autoload + PHPMailer
├── db/                    # SQLite + cache + uploads
└── docs/                  # Documentation
```

**Stack technique :**
- PHP 8.4 (strict_types, enums, readonly)
- SQLite (PDO)
- IIS (pas de .htaccess, web.config)
- Aucun framework, aucune dépendance externe (hors PHPMailer)
- CSS pur (zero JavaScript sauf indicateurs de progression)

---

## Routing

`index.php` est le **seul point d'entrée**. Il route vers `pages/xxx.php` via `?p=xxx`.

```
index.php?p=dashboard       → pages/dashboard.php
index.php?p=admin_forms     → pages/admin_forms.php
index.php?p=validate&token= → pages/validate.php
index.php?p=submission_view → pages/submission_view.php
```

La whitelist des pages autorisées est dans `index.php`. Les pages hors whitelist retournent 404.

**Auto-détection** : sans `?p=`, le router détecte la page depuis les paramètres :
- `?form_id=XXX` → `admin_forms`
- `?token=XXX` → `validate`
- `?id=XXX` → `submission_view`

---

## Architecture OOP (`src/`)

### Principe

Le code suit une architecture **hybride** : les pages (`pages/`) restent procédurales pour la simplicité, mais la logique métier est dans des **services** et **repositories** OOP dans `src/`.

### Container DI — `App` (singleton)

Tous les services sont enregistrés dans un container DI centralisé (`src/Core/App.php`). Accès global via les accesseurs statiques :

```php
// Depuis n'importe où dans le code
$pdo    = App::db()->getPdo();
$user   = App::auth()->getUser();
$mail   = App::mail();
$valid  = App::validation();
```

**Règle** : un service ne doit JAMAIS instancier ses dépendances directement. Il les reçoit via le constructeur (injection) ou les récupère du container.

### Enregistrement des services

`src/bootstrap.php` enregistre les 33 services dans l'ordre de dépendance :

```
Database → Config → SettingsRepository → SettingsService → ...
                ↓
         AdminRepository → AuthService (setMailer)
                ↓
         WorkflowEngine → TokenService
```

**Ordre important** : chaque service ne peut être enregistré APRÈS ses dépendances.

### Structure des namespaces

```
App\
├── Core\           # App (container), Database, Config, MigrationService
├── Auth\           # AuthService (authentification Windows IIS)
├── Security\       # SecurityService (CSRF, headers HTTP)
├── Settings\       # SettingsService → SettingsRepository
├── Forms\          # FieldService, ValidatorDataService
├── Workflow\       # WorkflowEngine, WorkflowService, ConditionEvaluator
├── Token\          # TokenService (régénération, annulation, relance, délégation)
├── Mail\           # MailService, MailerService
├── Render\         # HtmlService (escaping, jargon, fichiers)
├── View\           # ViewRenderer (rendu HTML), EmailView
├── Controller\     # BaseController, IndexController, DashboardController, FormController, PageController
├── Repository\     # BaseRepository + 7 repositories domaines
├── Audit\          # AuditLogService → AuditRepository
├── Cache\          # CacheService (file-based)
├── Attachment\     # AttachmentService → AttachmentRepository
├── Validation\     # ValidationService (règles d'entrée)
├── Email\          # EmailVerificationService (LDAP/SMTP)
├── Export\         # ExportService (CSV)
├── Stats\          # StatsService
├── Persona\        # PersonaService (token-based)
├── Rgpd\           # RgpdService (export, delete, purge)
├── Cron\           # CronService (lazy cron)
├── Webhook\        # WebhookService
├── Contract\       # Interfaces (AuthInterface, MailInterface, etc.)
└── SubmissionStatus.php  # Enum PHP 8.1
```

---

## Repository Pattern

Chaque domaine de données a un **repository** qui encapsule les requêtes SQL. Les repositories étendent `BaseRepository` qui fournit les helpers `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`.

| Repository | Tables | Responsabilité |
|-----------|--------|----------------|
| `AdminRepository` | `admins`, `admin_requests` | Gestion des administrateurs |
| `FormRepository` | `forms`, `form_fields`, `steps`, `form_owners` | Formulaires + workflow steps |
| `SubmissionRepository` | `submissions`, `submission_validator_data` | Soumissions + données validateur |
| `TokenRepository` | `tokens` | Tokens de validation |
| `AttachmentRepository` | `attachments` | Pièces jointes (BLOB) |
| `AuditRepository` | `audit_log` | Journal d'audit |
| `SettingsRepository` | `settings` | Paramètres clé/valeur |

**Comment ajouter un repository :**

1. Créer `src/Repository/MonRepository.php` qui étend `BaseRepository`
2. Ajouter `$this->table = 'ma_table';` dans le constructeur
3. Enregistrer dans `src/bootstrap.php` : `$app->set(MonRepository::class, new MonRepository($db));`
4. Utiliser : `App::getInstance()->get(MonRepository::class)->findByXxx()`

---

## Services métier

Chaque service encapsule une responsabilité métier et dépend de repositories ou d'autres services.

| Service | Dépendances | Responsabilité |
|---------|-------------|----------------|
| `AuthService` | Database, MailService | Authentification Windows, gestion admins |
| `SecurityService` | HtmlService | CSRF, headers HTTP sécurisés |
| `SettingsService` | SettingsRepository | Lecture/écriture/chiffrement settings |
| `WorkflowEngine` | Database, SettingsService, MailService, FieldService, ConditionEvaluator | Avancement workflow, validation tokens |
| `TokenService` | Database, SettingsService, AuthService, AuditLogService, MailService, WorkflowEngine | Régénération, annulation, relance, délégation |
| `MailService` | Database, SettingsService | Envoi emails via PHPMailer |
| `FieldService` | Database | Champs de formulaires |
| `ValidatorDataService` | Database | Données saisies par les validateurs |
| `ValidationService` | (aucune) | Validation d'entrées (uuid, email, slug, etc.) |
| `ExportService` | Database, AuthService | Export CSV |
| `EmailVerificationService` | CacheService | Vérification LDAP/SMTP |
| `AuditLogService` | AuditRepository | Journal d'audit |
| `CacheService` | (aucune) | Cache fichier avec TTL |
| `HtmlService` | (aucune) | Escaping `h()`, traduction jargon `tJargon()` |
| `RgpdService` | Database | Export/suppression/purge RGPD |
| `StatsService` | Database | Statistiques globales |
| `PersonaService` | Database | Persona admin→user (token-based) |
| `CronService` | Database | Lazy cron (relances + alertes) |
| `WebhookService` | Database, SettingsService | Notifications webhook |
| `AttachmentService` | AttachmentRepository | Upload + validation fichiers |

**Comment ajouter un service :**

1. Créer `src/MonDomaine/MonService.php`
2. Déclarer les dépendances dans le constructeur
3. Enregistrer dans `src/bootstrap.php`
4. Ajouter un accesseur statique dans `src/Core/App.php` (optionnel mais recommandé)

---

## Controllers

Les controllers étendent `BaseController` qui injecte 12 services depuis le DI container.

```php
class FormController extends BaseController {
    public function handle(): void {
        // $this->auth, $this->security, $this->mail, etc. disponibles
    }
}
```

| Controller | Pages | Rôle |
|-----------|-------|------|
| `IndexController` | `index.php` | Accueil (stats agent/admin, formulaires) |
| `DashboardController` | `dashboard.php` | Supervision admin |
| `FormController` | `form.php` | Soumission de formulaire |
| `PageController` | `pages/*.php` (legacy) | Shim qui charge un fichier page procédural |

**Note** : `PageController` est un migrateur. Les pages legacy (`pages/my_submissions.php`, etc.) sont encore procédurales. Elles seront progressivement migrées vers des controllers.

---

## Conventions de code

### Typage strict

```php
<?php declare(strict_types=1);

// TOUT le code src/ utilise strict_types
// Les paramètres sont typés
public function findById(string $id): ?array

// Les retours sont typés
public function getUser(): ?string
```

### Escaping

Toute sortie HTML passe par `h()` (alias de `HtmlService::escape()`) :

```php
<?= h($userInput) ?>
```

### Jargon

Le dictionnaire `tJargon()` traduit les termes techniques en langage courant pour les utilisateurs 40-60 ans :

```php
<?= tJargon('Circuit de validation')  // → "Étapes de validation"
 <?= tJargon('Workflow')               // → "Parcours"
 <?= tJargon('Token')                  // → "Lien de validation"
```

### Enum

`SubmissionStatus` est le seul enum PHP 8.1 du projet :

```php
enum SubmissionStatus: string {
    case EN_COURS = 'en_cours';
    case VALIDE = 'valide';
    case REFUSE = 'refuse';
    case ANNULE = 'annule';
}
```

---

## Base de données

### Schéma

SQLite, ~20 tables. Schéma créé par `classes/DatabaseMigrations.php` + `classes/migrations/v*.php`.

Tables principales :
- `forms` — formulaires (id, label, slug, deadline_field)
- `form_fields` — champs (field_name, field_type, filled_by, validator_step, visibility)
- `steps` — étapes de validation (ordre, label, condition)
- `step_recipients` — destinataires par étape
- `tokens` — tokens de validation (token, email, expires_at, done_at)
- `submissions` — soumissions (data JSON, status, submitted_by)
- `submission_validator_data` — données saisies par les validateurs
- `attachments` — pièces jointes (BLOB)
- `form_owners` — propriétaires de formulaires
- `admins` — administrateurs
- `settings` — paramètres clé/valeur
- `audit_log` — journal d'audit
- `alert_rules` / `alert_log` — alertes J-N
- `lazy_cron` — suivi des tâches planifiées
- `persona_tokens` — tokens persona admin→user
- `schema_version` — version du schéma

### Migrations

Chaque migration est versionnée (v10 à v26) et idempotente. La migration est exécutée automatiquement au boot via `MigrationService::migrate()`.

**Ajouter une migration :**

1. Créer `classes/migrations/v27.php` avec une fonction `apply_migration_v27(PDO $pdo): void`
2. Ajouter l'appel dans `classes/DatabaseMigrations.php`
3. Tester sur DB vierge ET DB existante

### Convention d'IDs

Toutes les clés primaires et étrangères sont des **UUID v4** (TEXT). Aucun INTEGER AUTOINCREMENT.

---

## Sécurité

- **Authentification** : Windows IIS + Kerberos (`$_SERVER['AUTH_USER']`)
- **CSRF** : tokens rotatifs (regénérés après chaque POST réussi)
- **Validation UUID** : tous les IDs d'URL sont validés (empêche IDOR)
- **Rate limiting** : IIS gère nativement (le rate limiting PHP a été supprimé en v10.3)
- **RGPD** : purge automatique configurable, export/suppression données agent
- **Headers** : CSP `default-src 'self'`, X-Content-Type-Options, HSTS (HTTPS)
- **Pas de CDN** : tout est local, CSP `default-src 'self'`

---

## Tests

### Suite de tests

| Fichier | Type | Nombre | Couverture |
|---------|------|--------|------------|
| `tests/PHPUnit/` | Unitaires | ~644 | Services, repositories, helpers |
| `tests/e2e/` | Playwright | ~50 | Rendu HTML, navigation, formulaires |
| `tests/test_all.php` | Fonctionnels | ~57 | Pages critiques |

### Gate qualité

`scripts/gate.sh` exécute avant chaque push :

1. Lint PHP (`php -l`)
2. PHPStan niveau 6 (0 erreur)
3. Tests PHPUnit
4. Tests échappement emails
5. Tests PHPMailer warnings
6. Tests assets + cache HTTP
7. Tests rendu HTML
8. Tests structurels HTML
9. Tests non-régression (bugs historiques)
10. Tests e2e Playwright

### Anti-régression

Chaque bug historique a un test dédié dans `tests/regression/` :

| Bug | Test | Ce qu'il vérifie |
|-----|------|-------------------|
| Bug01 | `Bug01_EndifFormControllerTest.php` | FormController endif mal placé |
| Bug02 | `Bug02_UploadFailureTest.php` | Upload échec silencieux |
| Bug03 | `Bug03_NestedFormsTest.php` | Forms imbriqués |
| Bug04 | `Bug04_ValidateExtraBraceTest.php` | validate.php extra } |
| Bug05 | `Bug05_StickyRgpdTest.php` | Checkbox RGPD non préservée |
| Bug06 | `Bug06_StickyValidateTest.php` | Motif/commentaire non préservés |
| Bug07 | `Bug07_FalseRefusedBadgeTest.php` | Faux badge "Refusé" |
| Bug08 | `Bug08_NoIsoDatesTest.php` | Dates ISO visibles |
| Bug09 | `Bug09_TopbarLinkTest.php` | Topbar "Nouvelle demande" |
| Bug10 | `Bug10_DuplicateLabelsHintsTest.php` | Labels/hints en double |
| Bug11 | `Bug11_NoTopbarBreadcrumbTest.php` | Topbar/breadcrumb |

---

## Patterns importants

### Façade procédurale

`helpers.php` est une façade de 83 lignes qui charge les modules `lib/` et délègue aux services OOP. Les anciennes fonctions globales (`get_pdo()`, `is_admin()`, etc.) existent toujours pour la rétrocompatibilité mais déléguent aux services.

### Lazy cron

Pas de cron externe (IIS n'a pas de crontab). Le premier utilisateur qui se connecte déclenche les tâches en retard via `run_lazy_cron()`. La table `lazy_cron` trace la dernière exécution.

### Persona admin→user

Un admin peut visualiser l'interface d'un agent via un token aléatoire propagé dans les URLs (`?persona_token=XXX`). Sécurité : downgrade uniquement, token expire après 8h.

### CSS servis par PHP

`assets.php` compile les 15 fichiers CSS en un seul blob avec cache HTTP (ETag + 304). Aucun CDN, aucun asset externe.

---

## Comment contribuer

### Ajouter une page

1. Créer `pages/ma_page.php`
2. Ajouter `ma_page` à la whitelist dans `index.php`
3. Créer le controller dans `src/Controller/MaPageController.php` (ou rester procédural)
4. Ajouter le lien dans `lib/render_navigation.php`

### Ajouter un service

1. Créer `src/MonDomaine/MonService.php` avec `declare(strict_types=1)`
2. Déclarer les dépendances dans le constructeur
3. Enregistrer dans `src/bootstrap.php`
4. Ajouter l'interface dans `src/Contract/MonInterface.php` (optionnel)
5. Ajouter un accesseur dans `src/Core/App.php`

### Ajouter un repository

1. Créer `src/Repository/MonRepository.php` extends `BaseRepository`
2. Enregistrer dans `src/bootstrap.php`
3. Utiliser via `App::getInstance()->get(MonRepository::class)`

### Ajouter un test anti-régression

1. Créer `tests/regression/BugXX_NomDuTest.php`
2. Le test doit FAIL si le bug est réintroduit
3. L'ajouter à `tests/regression/run_all.php`
4. Documenter le bug dans le test (symptôme, cause, fix)

---

## Bus factor

Le projet a un **bus factor = 1** (une seule personne connaît tout le code). Pour réduire ce risque :

1. **Ce document** existe pour cette raison
2. Chaque bug a un test qui l'aurait détecté
3. Le changelog documente chaque décision d'architecture
4. Les interfaces (`src/Contract/`) définissent les frontières entre modules

**Risque résiduel** : le déploiement (`update.ps1`, IIS, Windows) est mal documenté. Voir `docs/DEPLOY.md`.
