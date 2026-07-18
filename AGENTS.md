# Agent Guide — CircuitDémat

Guide technique pour agents IA travaillant sur le codebase CircuitDémat.

## KISS — Projet petit intranet

Ce projet est un **petit site intranet DREETS BFC** avec une charge utilisateur faible. Appliquer le principe KISS en permanence :

- **Pas de sur-architecture** : pas de cache superflu, pas de couches d'abstraction inutiles, pas de patterns lourds
- **Code court et direct** : préférer la simplicité même si c'est "moins optimal"
- **Sécurité gérée par IIS** : authentification Windows (AUTH_USER), autorisation IIS, rate limiting IIS. Le code PHP n'a pas besoin de gérer la session, le login, le logout, ni le rate limiting
- **Pas de features inutiles** : webhooks supprimés, features qui ne servent pas sont retirées
- **Quand c'est bon, c'est bon** : ne pas refactorer pour le plaisir, ne pas ajouter de tests edge-cases improbables

---

## Repository Pattern

Les repositories centralisent l'accès aux données. Ne pas utiliser `get_pdo()` directement.

### Fichiers
- `src/Repository/BaseRepository.php` — Abstract avec helpers `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`
- `src/Repository/FormRepository.php` — forms + form_fields + form_owners
- `src/Repository/SubmissionRepository.php` — submissions + validator_data
- `src/Repository/TokenRepository.php` — tokens + delegations
- `src/Repository/SettingsRepository.php` — settings
- `src/Repository/AdminRepository.php` — admins + admin_requests
- `src/Repository/AuditRepository.php` — audit_log + security_log
- `src/Repository/AttachmentRepository.php` — attachments

### Usage

```php
// Via DI
$repo = App::getInstance()->get(FormRepository::class);
$form = $repo->findById($id);

// Dans un service
public function __construct(private FormRepository $forms) {}
```

---

## Services (via DI container)

Tous les services sont enregistrés dans `src/bootstrap.php` et accessibles via `App::serviceName()`.

### Services existants
| Service | Classe | Static accessor |
|---------|--------|----------------|
| Auth | `App\Auth\AuthService` | `App::auth()` |
| Settings | `App\Settings\SettingsService` | `App::settings()` |
| Security | `App\Security\SecurityService` | `App::security()` |
| Mail | `App\Mail\MailService` | `App::mail()` |
| Audit | `App\Audit\AuditLogService` | `App::audit()` |
| Cache | `App\Cache\CacheService` | `App::cache()` |
| Html | `App\Render\HtmlService` | `App::html()` |
| Workflow | `App\Workflow\WorkflowEngine` | `App::workflow()` |
| Token | `App\Token\TokenService` | `App::token()` |
| ValidatorData | `App\Forms\ValidatorDataService` | `App::validatorData()` |
| Attachment | `App\Attachment\AttachmentService` | `App::attachment()` |
| Cron | `App\Cron\CronService` | `App::cron()` |
| Webhook | `App\Webhook\WebhookService` | — | Supprimé (getDbSize() conservé) |
| Fields | `App\Forms\FieldService` | `App::fields()` |

### Nouveaux services (v10.5.0)
| Service | Classe | Static accessor | Description |
|---------|--------|----------------|-------------|
| Validation | `App\Validation\ValidationService` | `App::validation()` | Validation/sanitisation d'entrées (uuid, email, slug, action, status, int, date, token) |
| EmailVerification | `App\Email\EmailVerificationService` | `App::emailVerify()` | Vérification email LDAP + SMTP |
| Export | `App\Export\ExportService` | `App::export()` | Export CSV streamé des soumissions |

### Règle
Toujours utiliser `App::serviceName()` ou injecter via constructeur. Ne jamais instancier un service directement (`new XxxService(...)`) hors de `src/bootstrap.php`.

---

## Documentation obligatoire

**Début de session** : TOUJOURS lire `CHANGELOG.md` et `TODO.md` pour connaître l'état actuel du projet.

**Fin de session** : TENIR À JOUR ces fichiers :

- **`CHANGELOG.md`** — Ajouter une entrée `[x.y.z]` avec la date, un résumé, et les sections (features, fixes, refactor, tests)
- **`TODO.md`** — Mettre à jour les métriques, cocher les tâches terminées, ajouter les nouvelles tâches restantes

Ces fichiers sont la source de vérité de l'état du projet. Ne jamais les oublier. Ils doivent toujours être dans le repo (pas dans le .gitignore).

---

## Contraintes réseau

- **SSH coupé** sur le réseau de l'utilisateur — ne jamais tenter `git push` via SSH
- **Proxy** : `http://127.0.0.1:3128` (si besoin pour curl/fetch)
- **Codeberg** : subit des erreurs 500/504 intermittentes (issue #2596) — le push HTTPS peut échouer, réessayer plus tard
- **Remote** : `https://codeberg.org/oliviernoblanc/formulaire-dematerialise.git` (HTTPS uniquement)
- **IIS prod** : pas d'accès web — vendor/ doit être commit, pas de `composer install` possible en prod. Les fichiers d'autoload doivent être à jour dans le repo.

---

## Règles de test

Après CHAQUE modification de code, TOUJOURS lancer les tests completset vérifier :
1. `vendor/bin/phpunit` — 0 failures
2. Vérifier que `vendor/composer/autoload_psr4.php` contient les nouvelles classes
3. Vérifier que aucun fichier supprimé n'est encore requis (grep)
4. Si modification d'un service/controller : vérifier aussi les contrôleurs enfants
5. Ne JAMAIS claim que c'est fini sans avoir lancé les tests

---

## PHPDoc & PHPStan — Typage strict obligatoire

PHPStan est configuré au **niveau 8** (max) avec `treatPhpDocTypesAsCertain: false`.

### Problème : `array<string, mixed>` est trop vague

```php
// FAUX — PHPStan ne peut PAS détecter les mauvaises clés
/** @return array<int, array<string, mixed>> */
public function getWorkflowSteps(string $formId): array
```

Avec `array<string, mixed>`, PHPStan ne sait pas quelles clés existent. `$step['id']` au lieu de `$step['step_id']` passe silencieusement.

### Solution : array shapes

```php
// CORRECT — PHPStan flagguera $step['id'] comme "undefined offset"
/**
 * @return array<int, array{
 *   step_id: string,
 *   step_label: string,
 *   ordre: int,
 *   actif: int,
 *   condition: string,
 *   recipient_emails: string
 * }>
 */
public function getWorkflowSteps(string $formId): array
```

### Règle

**Toute méthode qui retourne un tableau de données SQL** DOIT utiliser des array shapes précises, pas `array<string, mixed>`. Les clés doivent correspondre exactement aux aliases SQL (`AS` clause) ou aux noms de colonnes.

Cela s'applique à :
- Tous les méthodes de Repository (`fetchOne`, `fetchAll`)
- Tous les méthodes de Service qui retournent des données
- Les méthodes de WorkflowEngine, TokenService, etc.

### Pourquoi les tests n'ont rien vu

Les tests unitaires vérifient le **comportement** (bonne/mauvaise donnée retournée), mais pas la **cohérence des clés PHPDoc**. PHPStan est le seul outil capable de détector les accès à des clés inexistantes — mais seulement si les types sont explicites.

---

## Cohérence HTML/CSS

Le codebase a des fichiers CSS dans `lib/` et des renderers dans `src/Render/`. Les classes CSS utilisées dans le HTML doivent correspondre exactement à celles définies dans les CSS.

### Pattern dangereux

```php
// FAUX — génère du HTML inline avec les mauvaises classes
echo '<div class="wf-step-label">';  // devrait être wf-label
echo '<div class="wf-step done">';   // devrait être wf-step validated
```

### Pattern sûr

```php
// CORRECT — délègue au renderer qui produit le bon HTML
$renderer = new SubmissionViewRenderer();
echo $renderer->renderWorkflowDiagram($steps, $status);
```

### Règle

Ne **jamais** générer de HTML inline dans un controller pour des sections qui ont un renderer dédié dans `src/Render/`. Utiliser le renderer. Ajouter un test qui vérifie les classes CSS dans le HTML produit.

---

## Tests cross-platform

### Paths

- **JAMAIS** de paths hardcodés Linux dans les tests : `/tmp/`, `/home/z/`, `lsof`
- Utiliser `sys_get_temp_dir()` pour les fichiers temporaires
- Utiliser `php` (dans le PATH) au lieu de `/home/z/php/php`
- Pour tuer un process par port : la fonction `kill_port()` dans `test_bootstrap.php` est cross-platform (lsof sur Linux, netstat+taskkill sur Windows) et limitée à la plage 8760-8799

### curl dans les tests

- Toujours ajouter `CURLOPT_NOPROXY => 'localhost,127.0.0.1'` sur les handles curl — le proxy corporate peut intercepter les appels vers localhost

### test_form_render_html.php — pièges connus

Ce test invoque `FormController::handle()` dans un sous-processus PHP séparé. Pièges :

1. **TEST_MODE** : `core_bootstrap.php` définit `TEST_MODE` via `define()` (une seule fois). Si `helpers.php` est chargé, `TEST_MODE` est fixé. Pour tester le rendu HTML (pas JSON), il faut que `TEST_MODE=false` — ne PAS définir `APP_TEST_MODE` ni le header `HTTP_X_TEST_MODE`.
2. **CSRF** : en CLI, la session ne persiste pas entre sous-processus. Il faut peupler `$_SESSION['csrf_token']` dans le subprocess AVANT le controller, et `$_POST['csrf_token']` doit correspondre.
3. **SMTP** : `MailService::send()` tente une connexion SMTP en mode normal. Activer `mail_dry_run=1` via `\App\Core\App::settings()->set('mail_dry_run', '1', 'test')` pour éviter le blocage.
4. **POST data** : passer les données POST via `argv` (pas stdin) pour éviter que `stream_get_contents(STDIN)` n'écrase `$_POST`.
5. **lib_wrappers.php** : contient `test_json_response()` qui appelle `exit()`. En mode TEST_MODE=true, le controller l'appelle et le script meurt. En mode TEST_MODE=false, cette fonction n'est pas appelée.

### Règle

Après avoir corrigé un test, TOUJOURS lancer `php tests/<fichier>.php` directement pour vérifier, puis la gate complète (`pwsh -NoProfile -File scripts/check.ps1`). Ne jamais claim "c'est fini" sans avoir lancé les tests.

### Playwright — Firefox uniquement

Toujours utiliser **Firefox** pour les tests Playwright, jamais Chromium. Installer avec `rtk playwright install firefox`.

---

## Règle absolue — Pas de laisser-aller sur les bugs

**TOUS les bugs trouvés doivent être fixés.** Ne JAMAIS :
- Classer un échec de test comme "pré-existant" pour ne pas le fixer
- Dire "pas lié à mes changements" pour esquiver un fix
- Laisser des échecs de test dans la gate sans les corriger
- Prendre des raccourcis en marquant des tests comme skipped

Si un test échoue, c'est un bug. Point. Le corriger immédiatement, même s'il existait avant.

---

## Addendum — Enseignements de l'audit manuel (juillet 2026)

Un audit manuel complet a trouvé 16 bugs réels. PHPStan niveau 5 n'en a détecté qu'un seul. Les 15 autres partagent des causes communes non détectables par le typage.

### 1. Discipline de grep avant de clore une tâche

Avant de considérer une tâche terminée, si elle touche une colonne DB, une clé JSON, ou un champ partagé entre plusieurs fichiers, **grep ce nom sur tout le dépôt** et vérifie que chaque usage reste cohérent.

```bash
grep -rn "<nom_du_champ>" --include="*.php" .
```

### 2. Une seule source de vérité pour les listes de valeurs valides

Ne jamais dupliquer une liste de valeurs autorisées. Si elle existe déjà, la référencer (`ConditionEvaluator::VALID_OPS`), pas la recopier.

### 3. Ne jamais réutiliser un champ existant pour un nouveau sens

Si une colonne a déjà une sémantique établie (ex. `done_at` = "traité par l'utilisateur"), ne pas l'utiliser pour un nouveau sens ("invalidé par admin"). **Créer une colonne dédiée** (`invalidated_at`).

### 4. Hygiène du code mort

Avant d'écrire une nouvelle méthode, chercher si une équivalente existe (`grep -rn "function nomSimilaire"`). Si tu remplaces une implémentation, **supprime l'ancienne**.

### 5. Jamais de DateTime sans fuseau explicite

Toute comparaison de dates entre PHP et SQLite `datetime('now')` doit utiliser UTC partout ou une conversion explicite. `alert_check.php` comparait `Europe/Paris` (PHP) à UTC (SQLite) — double-alerte possible.

### 6. Cohérence texte/logique métier

Si un sujet d'email ou un label dépend d'une condition, le calcul doit être **factorisé en une seule variable** utilisée aux deux endroits, jamais dupliqué.

### 7. Tests à écrire systématiquement

Pour toute fonctionnalité avec champ obligatoire, import/export, ou donnée partagée :
- **Matrice type × obligatoire** (aurait attrapé le bug checkbox)
- **Round-trip import/export** avec chaque valeur d'enum
- **Invariant multi-fonctionnalités** après une action (ex. `delegate()` → vérifier `findDoneByEmail()`)
- **Comparaison texte/statut** avec chaque branche (positive, nulle, négative)

### 8. Pousser la validation vers la contrainte SQL

Pour toute colonne à valeurs limitées (statut, type, enum-like), ajouter une contrainte `CHECK` en base en plus de la validation PHP. Pour tout invariant "au plus un X actif", évaluer un index unique partiel plutôt que de compter sur le code applicatif.

### 9. Ne jamais avaler une exception sur un chemin critique

Un `catch` qui avale l'erreur et continue silencieusement sur un chemin d'écriture, d'audit, ou de conformité est interdit. L'erreur doit soit remonter, soit être surfacée de façon visible — jamais `error_log()` seul comme unique trace.

### Checklist avant de clore une tâche

1. Grep le champ/colonne/clé dans tout le dépôt
2. Pas de liste de valeurs dupliquée
3. Pas de réutilisation de champ existant pour un nouveau sens
4. Pas de méthode ancienne inutilisée laissée à côté
5. Dates avec fuseau explicite
6. Texte utilisateur dérivé du même calcul que la logique
7. Test du cas négatif/limite ajouté
8. Colonne enum-like ou invariant d'unicité → contrainte SQL (pas seulement PHP)
9. Tout `catch` sur chemin d'écriture/audit relance l'exception ou surface l'échec — jamais `error_log()` muet
