# Task 9: stats.php — 8 appels procéduraux → DI

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `require_admin()` | `App::auth()->requireAdmin()` |
| `display_user($email)` | `App::html()->displayUser($email)` |
| `render_donut_chart(...)` | `App::html()->renderDonutChart(...)` |
| `format_file_size($bytes)` | `App::html()->formatFileSize($bytes)` |
| `_dbm_q($pdo, "SQL")` | `$pdo->query("SQL")` |

## Testing

```bash
php -l pages/stats.php
```

## Report

Écrire dans `.superpowers/sdd/task-9-report.md`
