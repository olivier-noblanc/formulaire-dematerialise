# Agent Guide — CircuitDémat

Guide technique pour agents IA travaillant sur le codebase CircuitDémat.

## Superpowers

Avant toute tâche, invoquer les skills superpowers si applicables :
- `brainstorming` avant toute création/modification de feature
- `systematic-debugging` avant de fix un bug
- `writing-plans` avant un refactor multi-fichiers
- `test-driven-development` avant d'écrire du code
- `verification-before-completion` avant de claim que c'est fini

Voir `~/.claude/skills/using-superpowers/SKILL.md` pour la liste complète.

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
| Webhook | `App\Webhook\WebhookService` | `App::webhook()` |
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

Après chaque session de travail, TENIR À JOUR :

- **`CHANGELOG.md`** — Ajouter une entrée `[x.y.z]` avec la date, un résumé, et les sections (features, fixes, refactor, tests)
- **`TODO.md`** — Mettre à jour les métriques, cocher les tâches terminées, ajouter les nouvelles tâches restantes

Ces fichiers sont la source de vérité de l'état du projet. Ne jamais les oublier.
