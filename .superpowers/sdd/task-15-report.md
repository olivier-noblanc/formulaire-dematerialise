# Task 15 Report: Migrer AttachmentService vers AttachmentRepository

## What was implemented

Migrated `AttachmentService` to use `AttachmentRepository` instead of calling `getPdo()` directly.

### Changes made:

1. **`src/Attachment/AttachmentService.php`**:
   - Added `use App\Repository\AttachmentRepository` import
   - Added `private AttachmentRepository $repo` property
   - Updated constructor to accept `AttachmentRepository` as second parameter
   - Replaced `$this->db->getPdo()` INSERT with `$this->repo->create([...])` (line ~154)
   - Replaced `$this->db->getPdo()` SELECT by submission_id with `$this->repo->findBySubmission()` (line ~176)
   - Replaced `$this->db->getPdo()` SELECT by id with `$this->repo->findById()` (line ~187)

2. **`helpers.php`** (line 199-200):
   - Updated DI registration to fetch `AttachmentRepository` and pass it to `AttachmentService`

3. **`src/bootstrap.php`** (line 97-98):
   - Updated DI registration to fetch `AttachmentRepository` and pass it to `AttachmentService`

4. **`tests/phpunit_bootstrap.php`** (line 94):
   - Updated DI registration to pass `AttachmentRepository` to `AttachmentService`

5. **`tests/PHPUnit/AttachmentServiceTest.php`** (line 17):
   - Updated test setup to fetch `AttachmentRepository` from DI container and pass it to constructor

## Test results

- **531 tests, 821 assertions** — all PASS (OK)
- 19 skipped, 3 deprecations (all pre-existing, unrelated to this change)
- No errors or failures

## Commit

- **SHA**: `a6a5519`
- **Message**: `refactor: AttachmentService uses AttachmentRepository`
- **Author**: `onoblanc <olivier.noblanc@dreets.gouv.fr>`

## Files changed

1. `src/Attachment/AttachmentService.php` — core migration
2. `helpers.php` — DI wiring update
3. `src/bootstrap.php` — DI wiring update
4. `tests/phpunit_bootstrap.php` — DI wiring update
5. `tests/PHPUnit/AttachmentServiceTest.php` — test fix for new constructor signature
