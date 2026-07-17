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
