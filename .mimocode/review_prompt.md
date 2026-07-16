You are reviewing a bugfix for the CircuitDémat project (a small intranet workflow app for DREETS BFC).

## Bug Description
A user's colleague received a [RELANCE] email even though she already validated her step "Validation COM". The validation was at 13:57, the relance email was sent at 15:56 (2 hours later). The validation page shows "Déjà validé" when clicking the relance link.

## Root Cause (found by subagent investigation)
The `advanceWorkflow()` method in `src/Workflow/WorkflowEngine.php` can create DUPLICATE tokens for the same (submission_id, step_id, email) combination under concurrent access:

1. Two concurrent HTTP requests validate tokens from the same parallel group (same `ordre`)
2. Both call `advanceWorkflow()`
3. Both read `$tokensByStep` which shows the step has no tokens yet
4. Both pass the step-level check at line 202: `if (isset($tokensByStep[$step['step_id']])) { continue; }`
5. Both pass the dedup check at line 231: `SELECT 1 FROM tokens WHERE submission_id = ? AND step_id = ? AND email = ? AND done_at IS NULL` (this only checks for UNVALIDATED tokens)
6. Both INSERT a token for the same (submission_id, step_id, email)
7. The `tokens` table has `token TEXT UNIQUE NOT NULL` but NO unique constraint on `(submission_id, step_id, email)`
8. Both INSERTs succeed, creating a duplicate
9. One token gets validated (`done_at` set), the other remains `done_at IS NULL`
10. `remind.php` picks up the unvalidated duplicate and sends a relance

## Files Changed

### 1. New file: `classes/migrations/v27.php`
Creates a partial unique index to prevent duplicate unvalidated tokens at the DB level, and cleans up existing duplicates.

### 2. Modified: `classes/DatabaseMigrations.php`
- Updated loop from `$v <= 26` to `$v <= 27` to load the new migration
- Added `apply_migration_v27()` call after v26

### 3. Modified: `remind.php` (defense in depth)
The old code did a `fetchAll` of all pending tokens, then iterated without re-checking `done_at`. The new code processes each token in its own transaction with a fresh SELECT that re-validates conditions atomically.

## Review Checklist
Please review:
1. Is the partial unique index migration correct? Does it handle edge cases (existing duplicates, idempotency)?
2. Is the DELETE query for cleaning up existing duplicates correct?
3. Is the remind.php transactional approach correct? Are there any issues with the rollBack/commit logic?
4. Are there any other code paths that could create duplicate tokens (beyond the concurrent advanceWorkflow scenario)?
5. Is the `advanceWorkflow` method itself properly protected against concurrent access now, or does it need additional fixes?
6. Are there any performance concerns with the per-token transaction approach in remind.php?
7. Are there any edge cases where the fix could cause issues (e.g., regenerateToken, delegate, cancel)?
8. Should we also add a unique constraint check in the `advanceWorkflow` INSERT itself as defense in depth?

Read the relevant files (remind.php, classes/migrations/v27.php, classes/DatabaseMigrations.php, src/Workflow/WorkflowEngine.php) and provide a thorough code review. Focus on correctness and edge cases.
