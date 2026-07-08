# Task 16: Mettre à jour AGENT.md + CHANGELOG

**Files:**
- Modify: `AGENT.md`
- Modify: `CHANGELOG.md`

## Step 1: Ajouter section Repository dans AGENT.md

Ajouter après la section "Services" :

```markdown
## Repository Pattern

Les repositories centralisent l'accès aux données. Ne pas utiliser `get_pdo()` directement.

### Fichiers
- `src/Repository/BaseRepository.php` — Abstract avec helpers CRUD
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
```

## Step 2: Ajouter entrée CHANGELOG

Ajouter en haut de CHANGELOG.md :

```markdown
## [10.4.0] — 2026-07-08
_Résumé : Repository Pattern — centralisation de l'accès aux données._

### 🏗 Repository Pattern

- **BaseRepository** : abstract avec helpers `fetchOne()`, `fetchAll()`, `execute()`, `lastInsertId()`
- **7 Domain Repositories** : Form, Submission, Token, Settings, Admin, Audit, Attachment
- **Migration** : services src/ utilisent désormais les repositories au lieu de `getPdo()` direct
- **TDD** : tests unitaires pour chaque repository
- **PHP Modernization** : readonly, constructor promotion, union types sur les nouveaux fichiers
```

## Step 3: Commit

```bash
rtk git add AGENT.md CHANGELOG.md
rtk git commit --author="onoblanc <admin.local@exemple.invalid>" -m "docs: Repository Pattern documentation + CHANGELOG v10.4.0"
```
