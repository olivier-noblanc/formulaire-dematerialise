# Task 11: TokenRepository - Implementation Report

## What was implemented

Created `src/Repository/TokenRepository.php` extending `BaseRepository` with the following methods:

- `findByValue(string $token): ?array` - Find token by its value
- `findById(string $tokenId): ?array` - Find token by ID
- `findBySubmission(string $submissionId): array` - Find all tokens for a submission
- `create(array $data): string` - Create a new token, returns ID
- `markUsed(string $tokenId): bool` - Mark token as used (sets done_at)
- `markExpired(string $tokenId): bool` - Mark token as expired (sets expires_at to now)
- `incrementRelance(string $tokenId): bool` - Increment relance count and set relance_at
- `getActiveCount(string $formId): int` - Count active tokens for a form
- `getActiveCountByStep(string $stepId): int` - Count active tokens for a step

## Important note: Schema deviation from task brief

The task brief assumed column names that don't match the actual database schema:
- Used `email` instead of `to_email` (actual: `email`)
- Used `sent_at` instead of `created_at` (actual: `sent_at`)
- Used `done_at` instead of `used_at` (actual: `done_at`)
- Used `expires_at` instead of `expired_at` (actual: `expires_at`)
- Removed `action`, `status`, `done_by`, `comment` columns that don't exist in schema

The implementation matches the actual schema in `classes/migrations/schema_initial.php`.

## What was tested and test results

Created `tests/PHPUnit/Repository/TokenRepositoryTest.php` with 3 tests:

1. `testFindByValueReturnsNullForNonexistent` - PASS
2. `testFindBySubmissionReturnsArray` - PASS
3. `testGetActiveCountReturnsInt` - PASS

Full test suite: **531 tests, 821 assertions, all pass** (3 deprecations, 19 skipped - pre-existing)

## TDD Evidence

### RED phase
```
Tests: 3, Assertions: 0, Errors: 3
Error: Class "App\Repository\TokenRepository" not found
```

### GREEN phase
```
OK (3 tests, 5 assertions)
```

## Files changed

- `src/Repository/TokenRepository.php` (NEW)
- `tests/PHPUnit/Repository/TokenRepositoryTest.php` (NEW)

## Commits

- `d46f3a1` feat: TokenRepository (TDD)

## Concerns

- The task brief mentioned "tokens + delegations tables" but only implemented tokens. Delegations may be in a separate task.
- The schema deviation from the task brief was necessary to match the actual database structure.
