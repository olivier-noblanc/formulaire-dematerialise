# Task 5: AttachmentRepository — Report

## What I Implemented

`AttachmentRepository` extending `BaseRepository` with five methods:
- `findById(string $id): ?array` — single attachment lookup
- `findBySubmission(string $submissionId): array` — list attachments for a submission
- `create(array $data): string` — insert attachment, return generated UUID
- `delete(string $id): bool` — delete by primary key
- `deleteBySubmission(string $submissionId): bool` — delete all attachments for a submission

## Schema Adaptation

The task brief assumed columns `filename`, `size`, `data`, `created_at`. The actual `attachments` table uses:
- `original_name` (not `filename`)
- `stored_name` (always empty string in current service code)
- `file_size` (not `size`)
- `file_data` (not `data`)
- `uploaded_at` (not `created_at`)

Implementation was adapted to match the real schema.

## TDD Evidence

### RED
```
Tests: 2, Assertions: 0, Errors: 2.
Error: Class "App\Repository\AttachmentRepository" not found
```

### Autoloader Fix
`classmap-authoritative: true` in composer.json required `composer dump-autoload` after creating the new class file.

### GREEN
```
OK (2 tests, 3 assertions)
```

## Tests

| Test | Result |
|------|--------|
| `testFindByIdReturnsNullForNonexistent` | PASS |
| `testFindBySubmissionReturnsArray` | PASS |

## Full Suite Regression

519 tests, 804 assertions, 0 failures. Pre-existing deprecations (3) and skips (19) unchanged.

## Files Changed

- `src/Repository/AttachmentRepository.php` (new, 39 lines)
- `tests/PHPUnit/Repository/AttachmentRepositoryTest.php` (new, 32 lines)

## Commit

`99ff7e4` — `feat: AttachmentRepository (TDD)`

## Concerns

None. The implementation is minimal and matches the existing patterns used by `AuditRepository` and `SettingsRepository`.
