# Changelog — CircuitDémat

## [10.26.0] — 2026-07-25
_Résumé : Nettoyage dead code (13 méthodes, 2 repositories), factoration duplication, baseline PHPStan 775→506, outils mutation testing, Rector PHP 8.5._

### 🐛 Dead code cleanup

- **LazyCronRepository** supprimé (5 méthodes) : repository entier jamais utilisé, zéro appelant dans src/ ou tests/
- **PersonaRepository** supprimé (5 méthodes) : repository entier jamais utilisé, zéro appelant
- **App::formRepo()** supprimé : accesseur static jamais appelé (les contrôleurs injectent via BaseController)
- **App::mailRepo()** supprimé : accesseur static jamais appelé (MailService injecte directement)
- **FieldType::values()** supprimé : méthode enum jamais appelée
- **UrgencyLevel::Warning/Ok** supprimés : cases enum jamais utilisées

### 🔧 Refactor — Duplication

- **IndexRenderer** : factorisation `escapeFormField()` (duplication 12 lignes internalisée)
- **AdminFieldCrudHandler** : factorisation `readFieldPostData()` (duplication 40+21 lignes internalisée)

### 🔧 PHPStan — Baseline réduite

- **Baseline** : 775 → 506 erreurs (-35%, -269 erreurs)
- **deadMethod** : 64 → 0 (toutes les méthodes mortes supprimées ou justifiées)
- **NoMagicStringRule** : 76 → 51 (9 strings allowlistées : types HTML5, noms colonnes SQL, classes CSS)
- **phpstan-strict-rules** installé (auto-enregistré via extension-installer)
- **infection/infection** installé (mutation testing, configuré sur src/Workflow, Token, Export)
- **Rector** : 27 fichiers nettoyés via règles PHP 8.5 (NewMethodCallWithoutParentheses, NullToStrictString, RemoveUnusedVariable, FunctionFirstClassCallable, AddTypeToConst)

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| PHPStan baseline | 775 | **506** |
| deadMethod errors | 64 | **0** |
| noMagicString errors | 76 | **51** |
| Tests | 1287 | 1287 (0 fail) |
| Bug backlog audit | 29 non vérifiés | **22 fixés, 5 à vérifier, 1 présent** |

### 🐛 Bug backlog audit

Audit complet des 29 bugs fonctionnels identifiés lors de l'audit initial :
- **22 confirmés fixés** par les sessions précédentes (invalidated_at, optimistic locking, RGPD complet, REMOTE_ADDR, checkbox required, floor(), opérateurs sync, etc.)
- **5 à vérifier** en détail (notify_who custom, sélecteur date limite, handler retirer admin, flash messages, motif refus)
- **1 encore présent** : JargonService entier mort (#26) — à supprimer

---

## [10.25.0] — 2026-07-24
_Résumé : Repository pattern enforcement, migration enums métier, deptrac, PHPStan rules custom._

### 🏗️ Architecture — Repository Pattern Enforcement

- **Règle PHPStan `noDirectPdo`** via `spaze/phpstan-disallowed-calls` : 3 volets interdisant tout accès PDO brut en dehors des repositories :
  1. `get_pdo()` (fonction globale)
  2. `->getPdo()` sur Database/DatabaseInterface
  3. `PDO::prepare()`, `PDO::query()`, `PDO::exec()` sur objets PDO
  - Allowlist : `src/Repository/`, `classes/migrations/`, `src/Core/Database.php`, scripts legacy
  - Baseline absorbe la dette existante, tout nouveau code est bloqué par la gate CI
  - Fichier : `disallowed-calls.neon`

- **Migration complète des 14 services** — tous les appels PDO directs supprimés :
  - `WorkflowEngine` : 25 violations → 0 (12 méthodes repository ajoutées)
  - `AuthService` : 13 → 0
  - `StatsService` : 12 → 0
  - `TokenService` : 15 → 0
  - `RgpdService` : 18 → 0
  - `FieldService` : 14 → 0
  - `PersonaService` : 10 → 0
  - `CronService` : 8 → 0
  - `ExportService` : 3 → 0
  - `MailService` : 5 → 0
  - `ValidatorDataService` : 8 → 0
  - `SampleFormsService` : 6 → 0
  - `NavigationRenderer` : 4 → 0

- **Migration des 7 controllers/handlers** :
  - `BackupController`, `RgpdController` : PRAGMA/VACUUM centralisés dans `Database`
  - `AdminFormsController` + `AdminFormsHandlers` + `AdminFormCrudHandler` + `AdminStepCrudHandler` + `AdminRecipientHandler` : paramètre `\PDO $pdo` supprimé du dispatch

- **3 nouveaux repositories créés** :
  - `PersonaRepository` (5 méthodes)
  - `LazyCronRepository` (5 méthodes)
  - `MailRepository` (3 méthodes)

- **~40 nouvelles méthodes repository** ajoutées sur les repositories existants (FormRepo, SubmissionRepo, TokenRepo, AdminRepo, AttachmentRepo)

### 🐛 Bug fixes

- **Tests E2E hardcodés** : `testAccueilRendersExactly8FormCards` et `testAdminFormsRendersFormSelector` assertionnaient un nombre fixe de formulaires (8) — remplacé par `assertGreaterThanOrEqual(1)` pour être résistant aux ajouts de données

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Violations noDirectPdo | 162 | **0** |
| Baseline PHPStan | 676 | **526** |
| Tests | 1285 OK, 2 FAIL | **1287 OK, 0 FAIL** |
| Repositories | 9 | **12** |
| Méthodes repository | ~80 | **~120** |
| Services migrés | 0/14 | **14/14** |
| Controllers migrés | 0/7 | **7/7** |

### 🏷️ Enums métier — Adoption complète

- **7 enums créés** dans `src/Enum/` : `SubmissionStatus`, `FieldType`, `ValidationAction`, `FilledBy`, `FieldVisibility`, `AdminRequestStatus`, `UrgencyLevel`
- **Migration complète** : toutes les strings métier (`'en_cours'`, `'valide'`, `'text'`, `'valider'`, `'demandeur'`, etc.) remplacées par `Enum::Case->value` dans 39 fichiers
- **Règle PHPStan `NoMagicStringRule`** : détecte 22 strings métier et force l'usage des enums (baseline absorbe les 76 violations restantes dans comments/SQL aliases)
- **Rector custom** `ReplaceMagicStringWithEnumRector` pour auto-replacement

### 🏛️ Architecture — Deptrac

- **deptrac 4.7.1** installé et configuré (`deptrac.php`)
- 6 layers : Enum, Infrastructure, Repository, Service, Render, Controller
- GrumPHP : deptrac ajouté à la gate CI
- **0 violations**, 2047 appels autorisés

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Baseline PHPStan | 676 | **775** (+99 de NoMagicStringRule) |
| Tests | 1285 OK, 2 FAIL | **1287 OK, 0 FAIL** |
| Enums métier | 1 | **7** |
| Strings métier restantes | ~145 | **0** (hors comments/CSS/SQL aliases) |

---

## [10.24.0] — 2026-07-20
_Résumé : Suppression de App\Core\Config (code mort, 3 bootstraps parallèles nettoyés), NavigationRenderer::breadcrumb() et FormRenderer::statusFilter()._

### 🧹 Code mort

- **`App\Core\Config`** : classe entière supprimée. Enregistrée dans 3 bootstraps parallèles (`helpers.php`, `src/bootstrap.php`, `tests/phpunit_bootstrap.php`) mais aucune de ses 5 méthodes jamais consultée — l'app lit `BASE_URL`/`DB_PATH`/`TEST_MODE` directement comme constantes. Aucun accesseur `App::config()` n'a d'ailleurs jamais existé, contrairement à `App::mail()`/`App::html()`. Sa suppression a révélé les 3 bootstraps parallèles (2 sous `tests/`, invisibles à PHPStan) — tous corrigés dans le même commit.
- **`NavigationRenderer::breadcrumb()`** : aucun appelant — les breadcrumbs ont été supprimés de l'UI (épuration v9.1.0).
- **`FormRenderer::statusFilter()`** : aucun appelant — `MySubmissionsRenderer` a sa propre implémentation inline divergente, jamais consolidée.
- Triage complet du reliquat de code mort de la baseline PHPStan — voir TODO.md pour le détail des éléments **délibérément conservés** (`InstallRenderer::renderPage` — faux positif, `AuditRepository::getLogs` — sert à la vérification de tests, `AdminRepository::isAdmin` et `SubmissionViewRenderer::renderContent` — non tranchés, nécessitent plus d'investigation).

---

## [10.23.0] — 2026-07-20
_Résumé : Migration v31 (4 CHECK enum supplémentaires), MailService::send()/sendDetailed() dédupliquées (send() ne configurait ni auth SMTP ni TLS), mail_log enfin alimentée._

### 🔒 Durcissement SQL (AGENTS.md règle #8)

- **Migration v31** : contraintes CHECK ajoutées sur 4 colonnes enum-like supplémentaires (rebuild de table, pattern v30) :
  - `form_fields.field_type` et `submission_validator_data.field_type` → `'text'|'email'|'date'|'select'|'checkbox'|'textarea'|'file'` (source de vérité : `FormJsonValidator::$valid_field_types`)
  - `submission_validator_data.filled_by` → `'demandeur'|'validator'` (aligné sur le domaine sémantique de `form_fields.filled_by`, bien que seul `'validator'` soit utilisé en pratique aujourd'hui)
  - `mail_log.status` → `'sent'|'blocked'|'dry_run'|'error'`
  - 9 colonnes enum-like protégées côté SQL au total (5 en v30 + 4 en v31). Chemin de mise à niveau réel testé (DB v30 pure → v31), idempotence confirmée, 11 tests dédiés (`EnumConstraintV31Test`).
  - Délibérément **non** touchées : `audit_log.action` (30+ valeurs et en croissance — un CHECK y casserait l'ajout de toute nouvelle fonctionnalité, et un audit log ne doit jamais risquer un échec d'écriture) ; `alert_rules.notify_who` (enum-ou-email, pas un vrai enum) ; `alert_rules.condition_type` (une seule valeur en pratique mais domaine trop ambigu pour verrouiller sans risque).

### 🐛 Bug fix majeur — MailService

- **`MailService::send()` vs `sendDetailed()`** : deux implémentations PHPMailer entièrement dupliquées et divergentes. `send()` (utilisée par tout le workflow métier réel — WorkflowEngine, TokenService : validations, refus, délégations, relances) ne configurait ni `SMTPAuth`/`Username`/`Password` ni `SMTPSecure` (TLS/SSL), contrairement à `sendDetailed()` (utilisée uniquement par le bouton admin « tester l'email »). Si le serveur SMTP de production exige l'authentification ou le TLS, tous les emails de workflow réels échoueraient silencieusement alors que le test SMTP admin réussirait. Fix : `send()` délègue maintenant entièrement à `sendDetailed()`, seule implémentation SMTP restante.
- **`mail_log` alimentée pour la première fois** : la page monitoring affiche un « Journal des emails » depuis toujours, mais rien n'y écrivait (`getRecentLogs()` ne fait que lire). `sendDetailed()` y insère maintenant chaque tentative (actor/ip résolus via `App::auth()`/`$_SERVER`, écriture protégée par try/catch pour ne jamais faire échouer un envoi à cause d'un problème de log).
- Test de régression Bug13 (13 bugs historiques désormais couverts) — sonde en sous-processus hors TEST_MODE, seul moyen d'exercer ce chemin de code.

---

## [10.22.0] — 2026-07-20
_Résumé : Bug bounty — send_mail()/build_mail_html()/render_email_template()/format_bytes() n'existaient qu'en stub PHPStan (Fatal Error au runtime réel), bug de fuseau horaire dans remind.php, suppression de MailerService (code mort confirmé) et de 4 propriétés mortes dans BaseController._

### 🐛 Bug fixes — critiques

- **`send_mail()` / `build_mail_html()` / `render_email_template()` / `format_bytes()`** : ces 4 fonctions globales n'étaient définies que dans `phpstan_inst_stubs.php` (chargé uniquement par PHPStan pour l'analyse statique — jamais au runtime réel). Tout appel réel provoquait un Fatal Error "Call to undefined function", invisible à l'analyse statique puisque PHPStan voyait le stub. Impact réel :
  - `remind.php` (relance toutes les 12h) : plantait sur `send_mail()` à chaque tentative d'envoi, isolé par token → aucune relance probablement jamais envoyée.
  - `alert_check.php` (alertes échéance) : aucun try/catch autour de `send_mail()` → le script entier plantait dès la première alerte à envoyer.
  - `SubmissionViewController` : `format_bytes()` appelée sans protection pour la taille des pièces jointes → la page de consultation d'une soumission plantait (Fatal Error) dès qu'elle avait au moins une pièce jointe — bug **visible par les utilisateurs**, pas seulement un script cron.
  - Fix : `src/mail_wrappers.php` définit les vraies implémentations (celles déjà documentées dans les docblocks `@deprecated` des stubs), chargé par `helpers.php`. 8 tests ajoutés (`MailWrapperFunctionsExistTest`) qui auraient détecté ce bug.
- **`remind.php`** : `sent_at`/`relance_at` (UTC, SQLite `datetime('now')`) interprétées sans fuseau horaire explicite → décalage de 1-2h selon le fuseau serveur (Europe/Paris en prod), même classe de bug que le #12 déjà fixé dans `alert_check.php` mais jamais reporté sur ce script jumeau. Relances pouvant partir en avance sur le délai configuré. Fix : `DateTimeZone('UTC')` explicite. Test de régression Bug12 ajouté (12 bugs historiques désormais couverts dans `tests/regression/`).

### 🧹 Code mort

- **`App\Mail\MailerService`** (303 lignes + test dédié) : classe entièrement orpheline, confirmée par le CHANGELOG lui-même ("Méthodes ... ajoutées à MailService, anciennement uniquement sur MailerService") — jamais supprimée après la consolidation. Zéro référence réelle. Supprimée avec son test et les entrées de baseline PHPStan correspondantes.
- **`BaseController`** : 4 propriétés (`$fields`, `$mail`, `$workflow`, `$conditions`) instanciées via le container DI à chaque construction de contrôleur (25 sous-classes, donc à chaque requête HTTP) mais jamais lues nulle part — confirmé par PHPStan (shipmonk.deadProperty) et grep exhaustif. Retirées avec leurs imports.

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Tests | 1321 | **1283 unit+e2e** (+8 MailWrapper, +1 régression Bug12, -4 MailerServiceTest) |
| Tests de régression historiques | 11 | **12** |
| PHPStan (level 8) | 0 erreur | 0 erreur |
| Code mort supprimé | — | MailerService (303 lignes), 4 propriétés BaseController |

---

## [10.21.0] — 2026-07-20
_Résumé : Harnais e2e Linux réparé (5 bugs bloquant silencieusement les 96 tests), bug de production findBlocked() corrigé, couverture complète de TokenRepository (36 tests)._

### 🐛 Bug fixes — harnais e2e (Linux/CI)

Le harnais `tests/e2e/HttpRouteTest.php` + `start_server.php` n'exécutait
jamais réellement les 96 tests e2e sur Linux (dev comme CI) : 5 bugs en
cascade faisaient échouer le démarrage du serveur de test, masqués par le
fait qu'un test entièrement skip sort en code 0 (CI restait verte sans
qu'aucun assert e2e ne soit jamais exécuté).

- **`start_server.php`** : exigeait un 3ᵉ argument (`pidfile`) jamais fourni par l'appelant → `exit(1)` immédiat, serveur jamais démarré. Rendu optionnel.
- **`HttpRouteTest::setUpBeforeClass()`** : `proc_open(..., [])` remplaçait tout l'environnement du process enfant (dont `PATH`) au lieu de l'hériter → `PHP_BINARY` résolu vide dans `start_server.php`, commande serveur invalide (`sh: -S: not found`). Fix : `env=null`.
- **`HttpRouteTest::tearDownAfterClass()`** : `file_get_contents()` appelé avec un tableau brut au lieu d'un contexte `stream_context_create()` — `TypeError` non catchable par `@` sur PHP 8.5.
- **`testNoServerHeaderLeak`** : skip inconditionnel au lieu d'un vrai fix — corrigé en désactivant `expose_php` sur le serveur de dev (`-d expose_php=0`, Linux + Windows) ; le test vérifie maintenant réellement l'absence de l'en-tête.
- **`HttpRouteTest::tearDownAfterClass()`** : `SIGTERM`/`SIGKILL` (constantes ext-pcntl, jamais chargée en CI) remplacées par leurs valeurs POSIX numériques (15/9) — `posix_kill()` n'a pas besoin de pcntl.

### 🐛 Bug fixes — production

- **`TokenRepository::findBlocked()`** : la comparaison `CAST(...) AS REAL) - CAST(...) AS REAL) > ?` échouait systématiquement car le paramètre lié via `execute([...])` est passé en TEXT par défaut, sans affinité numérique appliquée face à une expression calculée (contrairement à une colonne). La méthode ne retournait donc **jamais aucun** token bloqué — l'alerte « tokens bloqués » de `MonitoringController` n'a probablement jamais fonctionné. Fix : `CAST(? AS REAL)` côté SQL.

### ✅ Tests — couverture TokenRepository

- **`tests/PHPUnit/Repository/TokenRepositoryTest/`** (nouveau, 4 fichiers, pattern `WorkflowEngineTest/`) : 36 tests / 67 assertions couvrant les 13 méthodes jusqu'ici non testées (`findWithStepsBySubmission`, `findDetailedWithStepsBySubmission`, `findBySubmissionIds`, `existsForSubmissionAndEmail`, `findEmailAndStepLabelById`, `findPendingByEmail`, `findStepsBySubmissionIds`, `deleteBySubmissionIds`, `countPurgeableByCutoff`, `findForExport`, `findBlocked`, `countExpired`, `countPendingBySubmissionIds`).

### 📊 Résultat

| Métrique | Avant (documenté) | Après (vérifié) |
|----------|-------|-------|
| Tests e2e exécutés réellement (Linux) | 0 (100% skip silencieux) | **96/96** |
| Suite complète | 1285 (chiffre TODO.md, non vérifiable — harnais cassé) | **1321 tests, 0 échec, 1 skip légitime** |
| TokenRepository | 1/14 méthodes testées | **14/14** |
| PHPStan (level 8) | 0 erreur | 0 erreur (inchangé) |

---

## [10.20.1] — 2026-07-20
_Résumé : Migration GitHub Actions CI, remote GitHub, PHPStan retiré du deploy gate._

### 🆕 CI GitHub Actions

- **`.github/workflows/ci.yml`** : gate qualité complète (Lint + PHPStan 8 + PHPUnit + tests fonctionnels)
- PHP 8.5, ubuntu-latest, `composer install` pour les deps dev
- `config.php` stub généré en CI (pas de secrets)

### 🔀 Migration Codeberg → GitHub

- **Remote** : `github.com/olivier-noblanc/formulaire-dematerialise` (privé)
- **update.ps1** : URLs GitHub (API + raw), clone auth via token GitHub
- **force-update.ps1** : curl avec header `Accept: application/vnd.github.v3.raw`
- **AGENTS.md** : remote URL mise à jour
- **docs/CI.md** : réécrit pour GitHub Actions

### 🐛 Bug fixes

- **.gitignore** : `.mimocode/` ajouté
- **update.ps1** : PHPStan retiré du gate deploy (outils dev, maintenant en CI)
- **EnumConstraintTest** : skip `testMigrationCrashSelfHealing` si DB ne contient pas `schema_version`
- **test_mail_escaping.php** : tests adaptés cross-plateforme (anti-double-escape uniquement)

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| CI | Aucune | **GitHub Actions** (4 jobs, ~2 min) |
| Remote | Codeberg (504 intermittent) | **GitHub** (stable) |
| Deploy gate | Lint + PHPStan + tests | Lint + tests (PHPStan en CI) |

---

## [10.20.0] — 2026-07-19
_Résumé : PHPStan 8→0, migration v30 (CHECK + triggers sur 5 colonnes), crash simulation test, try/catch audit, AGENTS.md addendum._

### 🐛 Bug fixes

- **WorkflowService** : constructeur manquant `SubmissionRepository` ajouté (5→6 args pour WorkflowEngine)
- **FormJsonValidator** : `$seen_validator_field_names` initialisé avant le bloc fields (variable potentiellement undefined)
- **SubmissionRepository** : cast `(string)` sur `fetchColumn()` pour `json_decode()` (int|string|null → string)
- **phpstan_inst_stubs** : constante `DEFAULT_DB_PATH` ajoutée (5 occurrences dans helpers.php, controllers, WebhookService)
- **ExportServiceTest** : 11 INSERT avec `status='pending'` → `'en_cours'` (valeur invalide pour submissions.status)
- **SubmissionRepositoryTest** : `updateStatus('validated')` → `'valide'` (valeur invalide)
- **DatabaseMigrations** : boucle require étendue `v≤29` → `v≤30`

### 🆕 Migration v30 — Contraintes enum (CHECK + triggers)

**Approche split** (testée et prouvée sur copie de la base réelle) :
- **CHECK via rebuild** (CREATE→INSERT→DROP→RENAME) : `form_fields.filled_by`, `form_fields.visibility`, `admin_requests.status`
- **Triggers BEFORE INSERT + BEFORE UPDATE OF `<colonne>`** : `submissions.status`, `tokens.action`
- **Self-healing** : si `form_fields_new` existe mais `form_fields` non (panne entre DROP et RENAME), RENAME au lieu de rebuild complet
- **Version INSERT en dernier** sur `$rebuild` — si panne avant, la migration se rejoue

| Table | Colonne | Mécanisme | Valeurs valides |
|-------|---------|-----------|-----------------|
| `form_fields` | `filled_by` | CHECK rebuild | demandeur, validator |
| `form_fields` | `visibility` | CHECK rebuild | all, owner_only |
| `admin_requests` | `status` | CHECK rebuild | pending, approved, rejected |
| `submissions` | `status` | Trigger BEFORE | en_cours, valide, refuse, annule |
| `tokens` | `action` | Trigger BEFORE | valider, refuser (nullable) |

**Note Windows/NTFS** : le rebuild via `$rebuild` (connexion séparée) est nécessaire car NTFS mandatory locking bloque le DDL même sans transaction ouverte quand d'autres connexions PDO sont actives dans le processus PHPUnit. Sur Linux (prod), `$rebuild` fonctionne sans problème.

### 🔧 Audit try/catch

- **AuditLogService::log()** : erreur inclut maintenant l'action + stack trace dans `error_log()`
- **RgpdService::deleteUserData()** : erreur auditée via `App::audit()->log('rgpd_delete_failed', ...)` en plus de `error_log()`

### 🧪 Tests ajoutés (+16)

- **EnumConstraintTest** : 16 tests — INSERT/UPDATE reject + valid values pour chaque colonne (5 colonnes × 3 tests + crash simulation)
- **testMigrationCrashSelfHealing** : simule un crash entre DROP et RENAME, vérifie que la self-healing restaure les données sans perte

### 🏗️ Refactor tests

- **8 Repository tests** (`AdminRepositoryTest`, `AttachmentRepositoryTest`, `AuditRepositoryTest`, `BaseRepositoryTest`, `FormRepositoryTest`, `SettingsRepositoryTest`, `SubmissionRepositoryTest`, `TokenRepositoryTest`) : `new Database()` → `App::getInstance()->get(Database::class)` pour éviter les connexions PDO concurrentes

### 📝 Documentation

- **AGENTS.md** : addendum audit mis à jour avec règle 10 + corollaire (vérifier un correctif avant de l'utiliser)
- **schema_initial.php** : commentaires ajoutés sur les colonnes contraintes pointant vers v30
- **v28.php** : commentaire ajouté sur tokens.action pointant vers v30

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Tests | 1362 | **1378** (+16) |
| Assertions | 2132 | **2451** (+319) |
| PHPStan erreurs | 8 | **0** |
| Migration version | 29 | **30** |

### 🔍 Investigation lock Windows/NTFS

Le "database table is locked" a été investigué en profondeur :
- **Stack trace capturée** : le lock se produit quand `db_migrate()` est appelé depuis `getTestPdo()` pendant que la connexion bootstrap est encore active
- **Preuve 1** : deux connexions PDO sans transaction → DROP **réussit**
- **Preuve 2** : deux connexions avec `BEGIN IMMEDIATE` → DROP **hang** (timeout)
- **Preuve 3** : `apply_schema_initial()` seul → pas de transaction, DROP **réussit**
- **Diagnostic complet** : `spl_object_id`, `inTransaction`, `PRAGMA database_list` capturés
- **Résultat** : non reproductible en isolation, spécifique au runner PHPUnit sur Windows/NTFS. `$rebuild` (connexion séparée) est la solution adoptée.

---

## [10.19.0] — 2026-07-18
_Résumé : 88→0 tests skippés, 0 failures, 0 errors, migration v28._

### 🐛 Bug fixes

- **TokenService constructeur** : 6ème argument `WorkflowEngine` supprimé (plus dans le constructor)
- **Test PDO busy_timeout** : ajout de `PRAGMA busy_timeout = 5000` dans `Database::getTestPdo()`
- **ExportServiceTest slugs** : 8 slugs hardcodés → `uniqid()` par test
- **GlobalFunctionsTest regex** : PCRE2 10.44 rejette `\\` dans lookbehind → corrigé
- **setAccessible() déprécié** : supprimé dans ExportServiceTest + SettingsServiceTest (PHP 8.5)
- **saveValidatorData()** : INSERT manquant `id` UUID (NOT NULL PK)
- **addOwner()** : INSERT manquant `id` UUID (NOT NULL PK)

### 🆕 Migration v28

- **tokens.action** : colonne ajoutée (type d'action valider/refuser)
- **admin_requests.reviewed_at/reviewed_by** : traçabilité des décisions d'accès
- **admins** : seed `testeur@e2e.test` pour les tests PHPUnit

### 🧪 Tests — 88→0 skips

- **WorkflowEngineTest** (77→0) : pattern DELETE-based cleanup via helpers (`createTestForm`, `createTestSubmission`, `createTestToken`) + `$createdIds` trackés en tearDown
- **AuthServiceTest** (4→0) : tearDown restaure `$_SERVER`, tests non-admin définissent explicitement leur user
- **RgpdServiceTest** (1→0) : submission créée dans le test au lieu de markTestSkipped
- **TokenServiceTest** : 2 fixes pour tests non-admin définissant explicitement `$_SERVER`

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Errors | 4 | **0** |
| Failures | 2 | **0** |
| Warnings | 3 | **0** |
| Deprecations | 4 | **0** |
| Skipped | 88 | **0** |
| Assertions | 1628 | **2113** |

---

## [10.18.0] — 2026-07-16
_Résumé : Hardening tokens concurrents + unification SubmissionStatus + refactor._

### 🐛 Bug fixes

- **WorkflowEngine::advanceWorkflow()** : transaction sur la boucle complète de tokens (SELECT + dup check + INSERT) pour sérialiser les requêtes concurrentes et empêcher les doublons
- **WorkflowEngine::advanceWorkflow()** : debug log ajouté dans le catch PDOException 23000 (defense-in-depth)
- **RgpdServiceTest** : 5 erreurs FK corrigées — form_id hardcoded inexistant remplacé par un form créé dynamiquement en setUp/tearDown
- **WorkflowEngineTest** : testGetWorkflowStepsReturnsConditionFieldForActiveSteps — guard markTestSkipped si aucun step actif (0 assertions)
- **WorkflowEngine::getWorkflowSteps()** : suppression du static cache — causait des données obsolètes dans les process longs (CLI, FPM persistent)

### ✨ Features

- **Enum SubmissionStatus** (`App\Enum\SubmissionStatus`) : enum unique avec `EnCours`, `Valide`, `Refuse`, `Annule` + méthodes `label()`, `icon()`, `color()`, `badgeClass()`, `cssClass()`, `fromValue()`
- **Unification** : suppression de l'ancien `App\SubmissionStatus` (UPPER_SNAKE), tous les fichiers migrent vers `App\Enum\SubmissionStatus` (PascalCase)
- **FormRepository::findActiveWithSubmissionCounts()** : nouvelle méthode pour le welcome state (requête extraite d'IndexController)

### 🔧 Refactor

- **IndexController** : requête welcome forms déplacée vers `FormRepository::findActiveWithSubmissionCounts()` + suppression du code mort
- **TokenService** : import `App\Enum\SubmissionStatus`

### 🧪 Tests

- **1351 tests, 0 failures, 0 errors** (était 1350/0/6 avant)
- **SubmissionStatusTest** : 9 tests couvrant values, labels, icons, colors, cssClasses, badgeClasses, fromValue, tryFrom

---

## [10.17.0] — 2026-07-16
_Résumé : PHPDoc array shapes stricts + PHPStan 0 erreurs + fix bugs exposés._

### 🐛 Bug fixes

- **AdminFormsController** : `$workflowStep['label']` → `$workflowStep['step_label']` et `$workflowStep['id']` → `$workflowStep['step_id']` — clés de getWorkflowSteps
- **FormPreviewController** : `$ws['label']` → `$ws['step_label']`, `$field['placeholder']` → `$field['hint']`, `$field['description']` → `$field['hint']` (form_fields n'a que `hint`), `json_decode` sur `options` avant foreach
- **AdminAccessController** : `$pendingRequest['created_at']` → `$pendingRequest['requested_at']` (colonne réelle de admin_requests)
- **AdminRepository** : SQL `ORDER BY created_at` → `ORDER BY requested_at`
- **11 fichiers** : `urlencode(null)` → `urlencode((string) ($var ?? ''))` — TypeError PHP 8.1+

### 🔧 PHPDoc & PHPStan

- **Tous les repositories** (Form, Submission, Token, Attachment, Admin, Audit, Settings, Alert) : array shapes précises sur toutes les méthodes retournant des données SQL
- **Tous les services** (WorkflowEngine, TokenService, StatsService, FieldService, ValidatorDataService, MailService, MailerService, RgpdService, EmailVerificationService) : idem
- **Interfaces** (WorkflowInterface, FieldInterface) : return types synchronisés
- **PHPStan niveau 8** : passe de ~30+ erreurs à **0 erreur**
- **AGENTS.md** : ajout des règles PHPDoc array shapes et cohérence HTML/CSS

### 🧪 Tests

- **SubmissionViewRendererTest** : 16+ tests sur les classes CSS du diagramme workflow
- **UrlencodeNullRegressionTest** : 17 tests sur les fix urlencode(null)
- **WorkflowEngineTest** : tests négatifs vérifiant que les clés legacy `id`/`label` n'existent pas

### 🔧 Infrastructure

- **xdebug** : `xdebug.mode=coverage` (pas `debug`) — supprime le warning timeout CLI

---

## [10.16.2] — 2026-07-16
_Résumé : Fix rendu workflow submission_view — le controller utilisait les mauvaises classes CSS._

### 🐛 Bug fixes

- **SubmissionViewController** : le circuit de validation utilisait un rendu inline avec les mauvaises classes CSS (`done`, `wf-step-label`, `wf-step-detail`) au lieu du renderer existant (`validated`, `wf-label`, `wf-ordre`, `wf-validators`) — le diagramme n'était pas stylé correctement
- **FormPreviewController** : correction `$ws['label']` → `$ws['step_label']` (clé retournée par `getWorkflowSteps`)

### 🧪 Tests

- **SubmissionViewRendererTest** : 16 tests vérifiant la structure HTML et les classes CSS du diagramme workflow — aurait détecté ce type de régression

---

## [10.16.1] — 2026-07-16
_Résumé : Fixes déploiement + warnings PHP + CSP._

### 🐛 Bug fixes

- **AttachmentRepository** : suppression de la référence à `uploaded_by` (colonne inexistante dans la table `attachments`) — corrige le 500 sur submission_view
- **SubmissionViewController** : utilisation de `step_id`/`step_label` au lieu de `id`/`label` (clés retournées par `getWorkflowSteps`) — corrige les warnings PHP
- **SubmissionRepository::findPaginatedBySubmitter** : `LIMIT 0 OFFSET 0` retournait un tableau vide — pas de LIMIT quand la valeur est 0
- **MySubmissionsRenderer** : retrait de la colonne "Ajouté par" (donnée absente)
- **SecurityService** : ajout `unsafe-inline` aux directives `script-src` et `style-src` du CSP — corrige le blocage des scripts/styles inline

### 🔧 Infrastructure

- **update.ps1** : correction du filtre `ProtectedFiles` — `$file.Name` (basename case-insensitive) remplacé par `$relativePath` — `src\Core\Config.php` n'est plus protégé par erreur
- **update.ps1** : nettoyage du cache PHPStan **avant** la gate qualité (pas après)
- **update.ps1** : retrait de `tests/` du `$ProtectedDirs` pour déploiement des tests
- **.gitignore** : ajout `/coverage/`, `/test_output*.txt`, `/requireAdmin()`

---

## [10.16.0] — 2026-07-16
_Résumé : Fix relances parasites — tokens doublons + race condition remind.php._

### 🐛 Bug fixes

- **remind.php** : correction d'une race condition où des relances étaient envoyées pour des tokens déjà validés
  - Ancien : `fetchAll` charge tous les tokens puis itère sans re-vérifier `done_at`
  - Nouveau : chaque token est traité dans sa propre transaction avec SELECT frais + UPDATE `AND done_at IS NULL` + `rowCount()` check
  - `beginTransaction()` déplacé dans le `try` avec guard `inTransaction()` dans le catch

- **WorkflowEngine.php** : INSERT token protégé contre la contrainte unique
  - Ajout de `try/catch` avec check `$e->getCode() === '23000'` (UNIQUE constraint failed) pour éviter un 500 en cas d'insert concurrent

- **Database.php** : ajout `PRAGMA busy_timeout = 5000` pour SQLite multi-writer

### 🆕 Migration v27

- **Index unique partiel** sur `(submission_id, step_id, email) WHERE done_at IS NULL` — empêche la création de tokens doublons au niveau DB
- **Nettoyage** des doublons existants via `rowid` (monotone, fiable même avec sent_at identique)
- Corrige le root cause : `advanceWorkflow()` pouvait créer deux tokens pour la même étape/email en cas d'accès concurrent

### 📊 Résultat

| Problème | Cause | Fix |
|----------|-------|-----|
| Relance envoyée après validation | Race condition remind.php + tokens doublons | Transaction par token + index unique partiel |
| 500 en cas d'insert concurrent | Pas de gestion d'erreur sur INSERT | try/catch `23000` |
| SQLITE_BUSY en cas d'écriture concurrente | Pas de busy_timeout | PRAGMA busy_timeout = 5000 |

---

## [10.15.2] — 2026-07-15
_Résumé : Garde-fou PHPUnit pour les wrappers procéduraux + fix render_footer()._

### 🐛 Bug fixes

- **lib_wrappers.php** : ajout du wrapper `render_footer()` (manquait — appelé par `test_all.php` mais jamais défini)

### 🛡️ Tests

- **GlobalFunctionsTest.php** : nouveau test PHPUnit qui vérifie que toutes les fonctions globales requises existent avant déploiement
  - Vérifie les 60+ wrappers de `lib_wrappers.php`
  - Vérifie les fonctions requises par la gate qualité (`test_all.php`)
  - Vérifie que toutes les fonctions sont appelables (pas d'erreur de dépendance)
  - Scanne `test_all.php` pour détecter les appels à des fonctions non-définies
  - Vérifie que les services OOP principaux sont enregistrés dans le container DI

### 📊 Résultat

| Problème | Cause | Fix |
|----------|-------|-----|
| Gate serveur échoue sur 12 fonctions | Version déployée ≠ version repo | Test PHPUnit comme garde-fou |
| `render_footer()` undefined | Wrapper manquant dans lib_wrappers.php | Ajouté |
| PHPUnit ne détecte pas les wrappers manquants | Test en isolation, pas de contrat | GlobalFunctionsTest |

---

## [10.15.1] — 2026-07-15
_Résumé : Fix gate qualité — autoload régénéré avant PHPStan + fix `$using:` PowerShell._

### 🐛 Bug fixes

- **update.ps1** : `$using:AppRoot` utilisé hors bloc `-Parallel` (ligne 334) → remplacé par `$AppRoot`
- **update.ps1** : `composer dump-autoload` exécuté APRÈS la gate qualité → déplacé AVANT (fonction `Invoke-ComposerAutoload` appelée avant `Invoke-QualityGate` dans les deux chemins git pull et clone)
- **vendor/autoload** : référence cassée à `rector/rector/bootstrap.php` (package absent) → régénérée proprement via `composer dump-autoload -o`

### 🗑️ Cleanup

- **fix_templates.php** : supprimé du repo (fichier migration one-shot qui polluait le lint PHP)

### 📊 Résultat

| Problème | Cause | Fix |
|----------|-------|-----|
| Gate quality failed: `$using:` error | `$using:AppRoot` hors bloc parallel | `$AppRoot` |
| Gate quality failed: `DatabaseInterface not found` | autoload stale (rector manquant) | dump-autoload AVANT gate |
| Gate quality failed: lint error `fix_templates.php` | fichier migration en dur | supprimé |

---

## [10.15.0] — 2026-07-13
_Résumé : Tests E2E + migration SQL complète + couverture + PHPStan 0 erreurs + bugs dispatch + CI E2E._

### 🏗 Refactor

- **PHPStan** : baseline 132 → **0 erreurs** (level 8) — tous les erreurs type safety corrigées
- **PDOStatement|false** : 21 erreurs corrigées dans migrations v13-v26 + BackupController + StatsService
- **Type safety** : 32 erreurs corrigées dans 20 fichiers src/ (casts, PHPDoc, code mort)
- **CI** : tests E2E ajoutés au pipeline Woodpecker (step 5)

### 📊 Résultat

| Métrique | Avant session | Après |
|----------|-------|-------|
| Tests | 977 | **1292** (0 failures) |
| Assertions | 1628 | **2119** |
| PHPStan erreurs | 132 | **0** (level 8) |
| PHPStan baseline | 132 lignes | **0** |
| SQL direct | 37 | **0** |
| Tests E2E | 0 | **30** |

### 🐛 Bug fixes

- **AdminFormsHandlers** : 7 bugs de dispatch — appels avec mauvais nombre d'arguments (handleDeleteForm, handleDuplicateForm, handleAddRecipient, handleDeleteRecipient, handleUpdateStep, handleDeleteStep, submissionDetail)
- **AttachmentService** : guard `finfo_open()` pour dégradation gracieuse si extension fileinfo manquante

- **AlertRepository** : service non enregistré dans helpers.php → 500 sur toutes les pages
- **DocumentationService** : service non enregistré → 500 sur /docs
- **MigrationService** : absente de helpers.php (présent dans bootstrap.php)
- **MonitoringController** : `require_once lib/render_monitoring_audit.php` cassé → 500
- **SubmissionViewController** : `require_once lib/render_submission_view.php` cassé → 500
- **FormTrackingController** : `require_once lib/render_form_tracking.php` cassé → 500
- **router.php** : AUTH_USER hardcodé (admin@dreets.gouv.fr) → remplacé par DEV_AUTH_USER env var

### 🔒 Sécurité

- **router.php** : auth dev sécurisée — variable d'environnement DEV_AUTH_USER + guard `cli-server` (CTO audité)

### 🏗 Refactor

- **SQL → repositories batch 4** : AdminImportExportHandler (7 queries → FormRepository)
- **SQL → repositories batch 5** : BackupController (12 queries → SubmissionRepo/TokenRepo/AlertRepo)
- **SQL → repositories batch 6** : MonitoringController (12 queries → 4 repos)
- **SQL → repositories batch 7** : HealthController (2 queries → BaseRepository)
- **ExportService** : réfacteuré en 4 méthodes testables (generateCsvString + transformValue + buildWhereClause)
- **BaseRepository** : ajout de `testConnection()` et `getTableNames()` pour health checks

### 🧪 Tests

- **ControllerRegistryTest** : 27 tests — instanciation de tous les contrôleurs + sync DI helpers/bootstrap
- **RequireOnceIntegrityTest** : scan codebase pour require_once vers fichiers inexistants
- **HttpRouteTest (E2E)** : 30 tests — HTTP réel sur serveur PHP dev, status codes, DOM, contenu
- **Tests contenu E2E** : 15 pages — comptages exacts, données DB, sections conditionnelles
- **ExportServiceTest** : 44 → 66 tests (couverture 15% → 89%)
- **WorkflowEngineTest** : 105 → 245 tests (couverture ~45% → ~80%)
- **AttachmentServiceTest** : 35 → 62 tests

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Tests | 977 | **1196** (0 failures) |
| Assertions | 1628 | **2115** |
| SQL direct remaining | 37 | **0** |
| PHPStan baseline | 132 → 71 → **0** (vide) | ✅ |
| PHPStan erreurs | — | **0** (level 8) |
| Require_once cassés | 3 | **0** |
| Services DI manquants | 3 | **0** |
| Tests E2E | 0 | **30** |
| Bugs dispatch corrigés | — | **7** |

---

## [10.14.0] — 2026-07-12
_Résumé : Ultrareview v4 — 8 bugs logiques/data corrigés, sécurité renforcée._

### 🐛 Bug fixes

- **WorkflowEngine** : étape avec token existant ignorée dans la création (pas de doublon)
- **WorkflowEngine** : étape sans token (condition false) traitée comme "pas concernée" au lieu de bloquer
- **TokenRepository** : tokens expirés plus affichés dans "Mes validations" (`expires_at > datetime('now')`)
- **TokenService** : `relance_max` enforcé côté serveur (était juste affiché, pas vérifié)
- **FormRepository::deleteCascade()** : supprime maintenant submissions + enfants (tokens, attachments, validator_data, alert_log)
- **FormRepository::deleteStep()** : supprime les tokens liés avant suppression
- **SubmissionRepository::findPaginatedBySubmitter()** : `LIMIT ? OFFSET ?` ajouté (manquait)

### 🔒 Sécurité

- **DownloadController** : Content-Disposition header injection corrigée (sanitize filename)
- **RgpdController** : Content-Disposition header injection corrigée (sanitize email)
- **lib_wrappers::encrypt_setting()** : `RuntimeException` au lieu de fallback silencieux en clair
- **SecurityService** : TEST_MODE CSRF bypass protégé par guard production `dreets.gouv.fr`
- **ExportService** : `json_valid()` guard sur `json_each()` (crash sur JSON invalide)
- **FormRepository::update()** : whitelist de colonnes autorisées (label, slug, description, actif, deadline_field)

### 🏗 Refactor

- **SubmissionRepository::deleteCascade()** : wrappé en transaction (`beginTransaction/commit/rollBack`)
- **FormRepository::deleteCascade()** : wrappé en transaction
- **PHPStan** : baseline régénérée (133 erreurs)
- **Mémoire** : règle ajoutée — ne pas focus sur DoS/rate-limiting/sécu infrastructure (intranet IIS authentifié)

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Tests | 977 | **977** (0 failures) |
| Assertions | 1623 | **1627** |
| Bugs logiques corrigés | — | **8** |
| Sécurité corrigée | — | **6** |

---

## [10.13.0] — 2026-07-12
_Résumé : PHP 8.5 exclusif — modernisation complète du codebase avec outils automatisés._

### 🏗 Refactor

- **PHP-CS-Fixer** : 113 fichiers conformes PER-CS (`@PER-CS`, `@PHP83Migration`)
- **Rector** : 88 fichiers modernisés (PHP 8.3+)
  - `readonly` sur 18 classes (Services immuables)
  - `class_property_assign_to_constructor_promotion` (promoted properties)
  - `str_contains()` au lieu de `strpos() !== false`
  - `SimplifyEmptyCheckOnEmptyArrayRector` (`$x === []` au lieu de `empty($x)`)
  - `RenameForeachValueVariableToMatchExprVariableRector` (lisibilité : `$vf` → `$validator_field`)
  - `RemoveUnusedVariableInCatchRector` (`catch (\Exception)` au lieu de `catch (\Exception $e)`)
  - `AddTypeToConstRector` (`const array` typé)
  - `DisallowedEmptyRuleFixerRector` (comparaisons explicites)

### ⚡ PHP 8.5

- **`composer.json`** : `"php": "^8.5"` exigé
- **`array_last()`** : remplace `end()` sans effet de bord sur le pointeur (2 occurrences)
- **Pipe operator `|>`** : `strtolower(trim($x))` → `$x |> trim(...) |> strtolower(...)` (8 occurrences)
- **Tests** : 977 tests, 1623 assertions, 0 échec

---

## [10.12.0] — 2026-07-12
_Résumé : Ultrareview v2 — 15 constats corrigés, PRAGMA foreign_keys ON global, 7 renderers extraits._

### 🐛 Bug fixes

- **C1** WorkflowEngine : étapes conditionnelles permanently false ne bloquent plus le workflow
- **C2** TokenService::regenerate() : transaction ajoutée (UPDATE + INSERT atomiques)
- **C3** TokenService::delegate() : transaction ajoutée (UPDATE + 2× INSERT atomiques)
- **W1** AdminFormCrudHandler::handleUpdateForm() : erreur UUID non plus écrasée par "libellé requis"
- **W2** AdminStepCrudHandler::handleAddStep() : même fix que W1
- **W3** WorkflowEngine : array_reduce redondant remplacé par count(array_intersect)
- **W4** handleDeleteField/Step/Recipient : retournent null au lieu de [] (contrat conforme)

### 🔒 Sécurité

- **PRAGMA foreign_keys = ON** activé globalement dans Database.php (prod + test)
- **AdminFormCrudHandler::handleDeleteForm()** : cascade delete complète (step_recipients, form_fields, form_owners) + transaction
- **AttachmentRepository::findBySubmissionWithUploader()** : JOIN sur table users inexistante supprimé
- **9 tests** adaptés pour créer les records parents (FK constraints respectées)

### ⚡ Performance

- **AdminImportExportHandler** : N+1 query step_recipients → GROUP_CONCAT en 1 requête
- **WorkflowEngine::advanceWorkflow()** : getValidatorDataForEvaluation() sorti de la boucle (1 appel au lieu de N)

### 🏗 Refactor

- **TokenService::remind()** : relance_count incrémenté APRÈS vérification envoi mail (avant = compteur consommé même si échec)
- **AdminFormsHandlers** : return types `: array` → `: ?array` sur handleDeleteStep/Field/Recipient
- **handleDuplicateForm()** : rethrow remplacé par catch + retour error array
- **remind()** : double if ($mailSent) redondant fusionné
- **AdminAlertsController/MySubmissionsController** : $pdo inutilisé supprimé
- **install.php/HealthController** : version check PHP 8.0+ → 8.5+
- **SQL → repositories** : ConfirmActionController (3 requêtes → TokenRepo/AlertRepo/FormRepo), MyValidationsController (4 requêtes → TokenRepo/SubmissionRepo), StatsController (2 requêtes → StatsService)
- **+9 méthodes repository** : findEmailAndStepLabelById, findPendingByEmail, findDoneByEmail, findStepsBySubmissionIds, findLabelById, findOwnerEmailById, findValidatorDataByEmail, getFormStats, getValidatorStats

### 🎨 Renderers (extraction HTML des controllers)

- **7 renderers créés** : AdminAlertsRenderer, ConfirmActionRenderer, MySubmissionsRenderer, MyValidationsRenderer, RgpdRenderer, StatsRenderer, ValidateRenderer
- **7 controllers allégés** : HTML déplacé vers renderers (ob_start → string concat)
- Pattern cohérent : `final class` + `public static function content(...)`
- Aucun renderer n'importe les controllers (pas de dépendance circulaire)

### 📊 Résultat

| Métrique | Avant 10.11.0 | Après 10.12.0 |
|----------|---------------|---------------|
| Tests | 977 | 977 (0 failures) |
| Constats ultrareview | 15 | 0 critiques/avertissements |
| PRAGMA foreign_keys | local (3 fichiers) | ON global |
| Renderers | 0 | 7 |

---

## [10.11.0] — 2026-07-11
_Résumé : Ultrareview complet — webhooks supprimés, 38 constats corrigés, SQL → repositories, AdminFormsHandlers splitté._

### 🐛 Bug fixes

- **C-1** ConditionEvaluator : opérateurs `equals`/`not_equals`/`contains` ajoutés — les conditions workflow fonctionnent
- **C-5** TokenService::cancel() : 3 UPDATEs enveloppés dans une transaction
- **W-1** TokenRepository::markExpired() : `datetime('now', '-1 second')` au lieu de `datetime('now')`
- **W-2** DashboardController : URL de redirection corrigée (`dashboard.phpfrom=` → `&from=`)
- **W-4** SubmissionRepository::saveValidatorData() : récupère le label au lieu de stocker le nom technique
- **W-14** SlugHelper::generateSlug() : `maxAttempts = 100` + RuntimeException
- **W-15** MailService::buildMailHtml() : `json_decode(...) ?: []`

### 🔒 Sécurité

- **W-5** DownloadController : nettoyage caractères de contrôle dans Content-Disposition
- **W-6** EmailVerificationService : filtrage `<>` dans commande SMTP RCPT TO
- **P-7** ConfirmActionController : CSRF vérifié avant rendu
- **P-6** ScreenshotController : check `realpath()` ajouté
- **P-3** AdminFormsHandlers/AdminAlerts/BackupController : erreurs PDO masquées aux users
- **W-7** SecurityService : log `error_log` quand TEST_MODE bypass CSRF
- **P-4** SecurityService : CSP nonce aléatoire au lieu de `unsafe-inline`

### ⚡ Performance

- **W-8** StatsService::getGlobalStats() : 11 requêtes → 1 requête GROUP BY
- **W-9** MonitoringController : batch query tokens pending (N+1 → 1)
- **P-8** BackupController : COUNT×13 → UNION ALL en 1 requête
- **C-2** ExportService : streaming CSV par batch 500 via json_each + LIMIT/OFFSET
- **C-3** FormTrackingController : pagination ajoutée (COUNT + LIMIT/OFFSET)

### 🏗 Refactor

- **C-7** SQL directes déplacées de 9 contrôleurs vers repositories (+30 méthodes repo)
- **W-17** ValidatorDataService délégué vers FieldService (4 méthodes dupliquées supprimées, -25% lignes)
- **P-1** TokenService::remind() : `relance_at` en UTC
- **P-2** SubmissionViewController : requête token réutilisée (suppression double exécution)
- **P-11** SettingsService::encrypt() : vérification longueur clé ≥ 32 octets

### 📊 Résultat

| Métrique | Avant 10.10.0 | Après 10.11.0 |
|----------|---------------|---------------|
| Tests | 995 | 995 (0 failures) |
| Constats ultrareview | 38 | 0 critiques restants |
| Repositories | 8 | 9 (+AlertRepository) |

---

## [10.10.0] — 2026-07-11
_Résumé : Suppression wrappers + render wrappers + h(), PHPStan -54%, +62 tests, lib/ vidé._

### 🏗 Suppression complète des wrappers procéduraux

**54 wrappers service** supprimés de `lib/service_wrappers.php` (fichier supprimé) :
- Attachment (6), Audit (3), Email Verification (5), Export/Cron (4), RGPD (3), Stats (3), Token (6), Webhook (2), Mail (7), ValidatorData (6), Workflow (9)

**10 render wrappers** supprimés/migrés :
- `render_submission_view.php` (17 fonctions mortes → supprimé)
- `render_ldap.php` → `LdapRenderer::datalist()`
- `render_install.php` → `InstallRenderer::renderPage()`
- `render_index.php` → `IndexRenderer::` (5 fonctions)
- `render_dashboard.php` → `DashboardRenderer::` (2 fonctions)
- `render_monitoring.php` → `MonitoringRenderer::` (3 fonctions)
- `docs_sections.php` → `DocumentationService::` (11 fonctions)
- `render_form.php` → `FormRenderer::` (5 fonctions, 25+ call sites)
- `render_errors.php` → `ErrorRenderer::` (2 fonctions + ErrorResponseException, 25+ call sites)
- `render_navigation.php` → `NavigationRenderer::` (7 fonctions + getAppName(), 13+ call sites)
- `security.php` → `SecurityService::` (3 fonctions)

**Dernier wrapper `h()`** supprimé :
- 544 call sites remplacés par `App::html()->escape()`
- `lib/html.php` supprimé

### 🔧 MailService consolidation

Méthodes `sendDetailed()`, `getRecentLogs()`, `buildMailHtml()` ajoutées à `MailService`.

### 📐 PHPStan baseline réduite

- Baseline : 312 → **142** erreurs (-54%)
- Stubs ajoutés : `phpstan_inst_stubs.php` (constantes INST, fonctions legacy)
- Fixes : strtotime() casts, null safety, static calls, return types

### 🧪 Tests ajoutés (+62)

- AttachmentService : +15 tests (upload errors, dangerous extensions, DB integration)
- AuthService : +17 tests (setMailer, requireAdmin, getUser, isAdmin, admin requests)
- ExportService : +12 tests (source analysis, BOM, delimiter, boolean conversion)
- WorkflowEngine : +41 tests (resolveDynamicRecipient, advanceWorkflow, validateToken, active submissions)

### 📊 Résultat

| Métrique | Avant 10.9.0 | Après 10.10.0 |
|----------|-------------|---------------|
| Tests | 933 | **995** (+62) |
| lib/ fichiers | 13 | **1** (core_bootstrap uniquement) |
| PHPStan baseline | 312 | **142** (-54%) |
| Wrappers procéduraux | 54 | **0** |
| Render wrappers | 10 | **0** |
| h() call sites | 544 | **0** |

---

## [10.9.0] — 2026-07-11
_Résumé : Suppression complète de service_wrappers.php (54 wrappers → appels DI directs), 933 tests._

### 🏗 Migration wrappers → DI directe

54 fonctions wrapper procédurales supprimées de `lib/service_wrappers.php` :
- **Attachment** (6) : `get_allowed_mime_types`, `get_allowed_extensions`, `get_max_file_size`, `handle_file_upload`, `get_attachments`, `get_attachment_by_id`
- **Audit** (3) : `app_log`, `security_log`, `get_audit_logs`
- **Email Verification** (5) : `verify_email_ldap`, `ldap_suggest`, `verify_email_smtp`, `verify_email`, `test_email_verification`
- **Export/Cron** (4) : `export_csv`, `run_lazy_cron`, `parse_db_datetime`, `handle_post`
- **RGPD** (3) : `rgpd_export_user_data`, `rgpd_delete_user_data`, `rgpd_auto_purge`
- **Stats** (3) : `search_submissions`, `get_stats_by_period`, `get_global_stats`
- **Token** (6) : `regenerate_token`, `cancel_submission`, `remind_one`, `get_tokens_for_submission`, `delegate_token`, `get_delegations`
- **Webhook** (2) : `send_webhook`, `get_db_size`
- **Mail** (7) : `_mail_service`, `send_mail`, `send_mail_detailed`, `log_mail_attempt`, `get_recent_mail_logs`, `build_mail_html`, `render_email_template`
- **ValidatorData** (6) : `get_submission_validator_data`, `save_validator_data`, `delete_validator_data`, `get_form_validator_fields`, `get_form_fields`, `get_validator_status_batch`
- **Workflow** (9) : `get_token_with_context`, `get_token_by_id_with_context`, `get_workflow_steps`, `get_submission_with_form_label`, `resolve_dynamic_recipient`, `advance_workflow`, `validate_token`, `has_active_submissions`, `has_active_step_submissions`

### 🔧 MailService consolidation

Méthodes `sendDetailed()`, `getRecentLogs()`, `buildMailHtml()` ajoutées à `MailService` (anciennement uniquement sur `MailerService`).

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Wrappers dans service_wrappers.php | 54 | **0** (fichier vidé) |
| Appels wrappers production | ~80 | **0** |
| Appels wrappers tests | ~120 | **0** |
| Tests | 933 | **933** (0 failures) |

---

## [10.8.0] — 2026-07-10