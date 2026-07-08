# Task 12: Register Submission + Token in DI — Report

## What I implemented

1. Added registration of `SubmissionRepository` and `TokenRepository` in three files:
   - `helpers.php` (after FormRepository)
   - `src/bootstrap.php` (after FormRepository)
   - `tests/phpunit_bootstrap.php` (after FormRepository)

2. Added necessary `use` statements in `src/bootstrap.php` and `tests/phpunit_bootstrap.php`.

## What I tested and test results

- Ran `rtk php phpunit.phar` which executed 531 tests.
- All tests passed (OK, but there were issues due to skipped tests and deprecations, not failures).
- Test count 531 exceeds the expected 504+ tests.

## Files changed

- `helpers.php` — added two lines for SubmissionRepository and TokenRepository registration.
- `src/bootstrap.php` — added two `use` statements and two registration lines.
- `tests/phpunit_bootstrap.php` — added two `use` statements and two registration lines.

## Issues or concerns

- No issues encountered. The implementation follows the exact specification in the task brief.
- The test suite runs successfully with no failures.