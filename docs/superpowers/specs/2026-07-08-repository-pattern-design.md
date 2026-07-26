# Design Spec — Repository Pattern pour CircuitDémat

**Date :** 2026-07-08
**Auteur :** CTO (MiMoCode)
**Statut :** Approuvé

---

## Contexte

Le codebase utilise `get_pdo()` de manière dispersée (139 appels dans pages/, lib/, src/). Les services src/ utilisent déjà `$this->db->getPdo()` via DI, mais les pages/ et lib/ appellent encore la fonction globale. Ce pattern :
- Rend le code difficile à tester (couplage à la DB)
- Duplique les requêtes SQL dans plusieurs fichiers
- Empêche le mocking pour les tests unitaires

## Objectif

Créer une couche Repository qui centralise l'accès aux données, élimine les appels `get_pdo()` dispersés, et enable le mocking pour les tests.

## Architecture

### Structure

```
src/Repository/
├── BaseRepository.php          # Abstract : PDO injection, query helpers
├── FormRepository.php          # forms + form_fields + form_owners
├── SubmissionRepository.php    # submissions + submission_validator_data
├── TokenRepository.php         # tokens + delegations
├── SettingsRepository.php      # settings
├── AdminRepository.php         # admins + admin_requests
├── AuditRepository.php         # audit_log + security_log
└── AttachmentRepository.php    # attachments
```

### BaseRepository

```php
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Database;
use PDO;

abstract class BaseRepository
{
    public function __construct(protected Database $db) {}
    
    protected function pdo(): PDO
    {
        return $this->db->getPdo();
    }
    
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }
    
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo()->prepare($sql);
        return $stmt->execute($params);
    }
    
    protected function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }
}
```

### Domain Repositories

Chaque repository expose des méthodes métier qui encapsulent les requêtes SQL.

**FormRepository :**
```php
class FormRepository extends BaseRepository
{
    public function findById(string $id): ?array;
    public function findBySlug(string $slug): ?array;
    public function findAll(bool $activeOnly = false): array;
    public function findOwnedBy(string $email): array;
    public function create(array $data): string;
    public function update(string $id, array $data): bool;
    public function delete(string $id): bool;
    public function getFields(string $formId): array;
    public function getSteps(string $formId): array;
    public function getOwners(string $formId): array;
    public function addOwner(string $formId, string $email): bool;
    public function removeOwner(string $formId, string $email): bool;
}
```

**SubmissionRepository :**
```php
class SubmissionRepository extends BaseRepository
{
    public function findById(string $id): ?array;
    public function findByForm(string $formId, ?string $status = null): array;
    public function findBySubmitter(string $email): array;
    public function findPendingForValidator(string $email): array;
    public function create(array $data): string;
    public function updateStatus(string $id, string $status): bool;
    public function getValidatorData(string $submissionId, ?string $stepId = null): array;
    public function saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void;
    public function deleteValidatorData(string $submissionId, string $fieldName): void;
}
```

**TokenRepository :**
```php
class TokenRepository extends BaseRepository
{
    public function findByValue(string $token): ?array;
    public function findById(string $tokenId): ?array;
    public function findBySubmission(string $submissionId): array;
    public function create(array $data): string;
    public function markUsed(string $tokenId, string $doneBy, ?string $comment = null): bool;
    public function markExpired(string $tokenId): bool;
    public function incrementRelance(string $tokenId): bool;
    public function getActiveCount(string $formId): int;
    public function getActiveCountByStep(string $stepId): int;
}
```

**SettingsRepository :**
```php
class SettingsRepository extends BaseRepository
{
    public function get(string $key, string $default = ''): ?string;
    public function set(string $key, string $value, string $updatedBy = ''): bool;
    public function delete(string $key): bool;
    public function getAll(): array;
}
```

**AdminRepository :**
```php
class AdminRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array;
    public function isAdmin(string $email): bool;
    public function isSuperAdmin(string $email): bool;
    public function getAll(): array;
    public function add(string $email, string $addedBy): bool;
    public function remove(string $email): bool;
    public function getPendingRequests(): array;
    public function approveRequest(string $requestId, string $approvedBy): bool;
    public function rejectRequest(string $requestId, string $rejectedBy): bool;
}
```

**AuditRepository :**
```php
class AuditRepository extends BaseRepository
{
    public function log(string $action, string $target, string $detail, string $actor): bool;
    public function securityLog(string $event, string $detail, string $actor): bool;
    public function getLogs(int $limit = 100, string $actionFilter = ''): array;
    public function getSecurityLogs(int $limit = 100): array;
}
```

**AttachmentRepository :**
```php
class AttachmentRepository extends BaseRepository
{
    public function findById(string $id): ?array;
    public function findBySubmission(string $submissionId): array;
    public function create(array $data): string;
    public function delete(string $id): bool;
    public function deleteBySubmission(string $submissionId): bool;
}
```

## Stratégie de migration

### Phase 1 : Création des repositories (TDD)

| Ordre | Repository | Complexité | Priorité |
|-------|-----------|------------|----------|
| 1 | SettingsRepository | Faible | Haute |
| 2 | AuditRepository | Faible | Haute |
| 3 | AttachmentRepository | Faible | Haute |
| 4 | AdminRepository | Moyen | Haute |
| 5 | FormRepository | Moyen | Moyenne |
| 6 | SubmissionRepository | Élevé | Moyenne |
| 7 | TokenRepository | Élevé | Moyenne |

### Phase 2 : Migration des appelants

1. **Services src/** : remplacer `$this->db->getPdo()` par `$this->{repoRepository}->method()`
2. **lib/** : remplacer `get_pdo()` par `App::{repoRepository}()->method()`
3. **pages/** : remplacer `get_pdo()` par `App::{repoRepository}()->method()`

### Phase 3 : Nettoyage

1. Supprimer les fonctions globales devenues inutiles
2. Mettre à jour les tests existants
3. `composer dump-autoload`

## PHP Modernization

Sur les nouveaux fichiers Repository :
- `readonly` properties pour les résultats de requêtes
- Constructor property promotion
- Union types `?array`
- `match` expressions pour les conditions
- Named arguments pour la lisibilité

## Validation

1. Chaque repository a ses tests unitaires (TDD)
2. 504 tests existants doivent continuer à passer
3. `composer dump-autoload` après ajout des fichiers
4. Couverture de tests maintenue ou améliorée

## Risques

| Risque | Impact | Mitigation |
|--------|--------|------------|
| Régression sur les pages/ | Élevé | Tests E2E existants |
| Performance (appels supplémentaires) | Faible | Le PDO est déjà un singleton |
| Complexité accrue | Moyen | Documentation claire, patterns existants |

## Livrables

1. `src/Repository/BaseRepository.php`
2. 7 Domain Repositories
3. Tests unitaires pour chaque repository
4. Migration des services src/
5. Migration de lib/ et pages/ (Phase 2)
6. Documentation dans AGENT.md
