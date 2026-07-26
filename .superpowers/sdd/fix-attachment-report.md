# Fix: AttachmentRepository INSERT column/placeholder mismatch

## What I found

**Actual schema** (`attachments` table — 9 columns):
```
id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at
```

**Previous code (broken):**
```php
$this->execute(
    "INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, '', ?, ?, ?, datetime('now'))",
    [$id, $data['submission_id'], $data['field_name'], $data['original_name'], $data['mime_type'], $data['file_size'], $data['file_data']]
);
```

**Bug:** 9 columns listed but only 8 values in VALUES (7 `?` + 1 `''` literal + 1 `datetime('now')`). Worse, the parameter mapping was wrong:
- `original_name` column got hardcoded `''` (empty string) instead of the actual filename
- `$data['original_name']` was bound to `stored_name` column
- The `AttachmentService` caller never passed `stored_name`, so this would fail at runtime

## What I fixed

**Fixed INSERT statement** — all 9 columns now get proper values:
```php
$this->execute(
    "INSERT INTO attachments (id, submission_id, field_name, original_name, stored_name, mime_type, file_size, file_data, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))",
    [$id, $data['submission_id'], $data['field_name'], $data['original_name'], $data['original_name'], $data['mime_type'], $data['file_size'], $data['file_data']]
);
```

- Uses 8 `?` placeholders (one per bind parameter) + 1 `datetime('now')` literal
- Binds `original_name` for both `original_name` and `stored_name` columns (since files are stored as BLOBs, not on filesystem, both names are the same)

## Test results

```
PHPUnit: 532 tests, 836 assertions, 19 skipped
```

New round-trip test `testCreateAndReadBackRoundTrip` verifies:
- `create()` returns a valid UUID
- `findById()` returns all fields with correct values
- `original_name` and `stored_name` are both set to the provided filename
- `findBySubmission()` returns the attachment
- `delete()` removes the attachment

## Files changed

- `src/Repository/AttachmentRepository.php` — fixed INSERT statement (line 25)
- `tests/PHPUnit/Repository/AttachmentRepositoryTest.php` — added round-trip test
