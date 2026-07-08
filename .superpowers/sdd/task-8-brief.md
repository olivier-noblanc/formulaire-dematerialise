# Task 8: submission_view.php — 4 appels procéduraux → DI

## Mapping

| Wrapper | Remplacement |
|---------|-------------|
| `get_auth_user()` | `App::auth()->getUser()` |
| `is_admin_effective()` | `App::auth()->isAdminEffective()` |
| `is_form_owner($form_id)` | `App::auth()->isFormOwner($form_id)` |

## Testing

```bash
php -l pages/submission_view.php
```

## Report

Écrire dans `.superpowers/sdd/task-8-report.md`
