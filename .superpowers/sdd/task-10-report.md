# Task 10: SubmissionRepository — Report

## What was implemented

`SubmissionRepository` extending `BaseRepository` with the following methods:

- `findById(string $id): ?array` — single submission lookup
- `findByForm(string $formId, ?string $status = null): array` — list by form, optional status filter
- `findBySubmitter(string $email): array` — list by submitter email
- `findPendingForValidator(string $email): array` — JOIN with tokens for active pending validations
- `create(array $data): string` — insert with UUID generation
- `updateStatus(string $id, string $status): bool` — status update
- `getValidatorData(string $submissionId, ?string $stepId = null): array` — validator field data
- `saveValidatorData(string $submissionId, string $fieldName, string $value, string $filledBy, ?string $stepId = null): void` — upsert via INSERT OR REPLACE
- `deleteValidatorData(string $submissionId, string $fieldName): void` — delete validator field

### Deviations from brief

The brief's SQL had column names that don't match the actual schema. Fixed:
- `findPendingForValidator`: `t.email` (not `t.to_email`), `t.done_at` (not `t.used_at`), `t.expires_at` (not `t.expired_at`), ordered by `t.sent_at`
- `saveValidatorData`: Added `field_label` (NOT NULL in schema), uses `filled_by='validator'` and `filled_by_email` columns to match actual table definition

## TDD Evidence

**RED**: Tests run before implementation — 3 errors, "Class App\Repository\SubmissionRepository not found"
**GREEN**: Tests run after implementation — 3 tests, 5 assertions, OK

Full suite: 528 tests, 816 assertions, 0 failures.

## Files changed

- `src/Repository/SubmissionRepository.php` (created)
- `tests/PHPUnit/repository/SubmissionRepositoryTest.php` (created)

## Commit

`59443f8` — `feat: SubmissionRepository (TDD)`

## Concerns

None. All 9 public methods implemented per the task spec. Schema deviations were necessary to match the actual database columns.
