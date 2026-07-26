# Task 9: Refactor pages/stats.php — Replace procedural wrappers with DI calls

## Summary

Refactored `pages/stats.php` to use direct dependency injection calls instead of procedural wrapper functions.

## Changes Made

### 1. pages/stats.php

| Before | After |
|--------|-------|
| `require_admin()` | `\App\Core\App::auth()->requireAdmin()` |
| `_dbm_q($pdo, "...")` | `$pdo->query("...")` |
| `render_donut_chart(...)` | `\App\Core\App::html()->renderDonutChart(...)` |
| `display_user($vs['email'])` | `\App\Core\App::html()->displayUser($vs['email'])` |
| `format_file_size(...)` | `\App\Core\App::html()->formatFileSize(...)` |

### 2. src/Contract/HtmlInterface.php

Added two new method signatures to the interface:
- `displayUser(string $email, ?string $current_user = null, bool $force_email = false): string`
- `renderDonutChart(int $total, int $valide, int $en_cours, int $refuse): string`

### 3. src/Render/HtmlService.php

Implemented the two new methods, porting logic from `lib/html.php`:
- `displayUser()` — masks email domain for current user, shows "Vous" for self
- `renderDonutChart()` — generates SVG donut chart with conic-gradient

## Verification

All three files pass `php -l` syntax check.

## Notes

- `get_global_stats()` and `get_stats_by_period()` remain as procedural calls from `helpers.php` — these are not yet migrated to DI (tracked in separate tasks).
- `get_db_size()` also remains procedural (outside scope of this task).
- The `h()` function calls in the template remain unchanged as they're used inline in HTML and the HtmlService `h()` method is already available via `\App\Core\App::html()->h()` if needed later.
