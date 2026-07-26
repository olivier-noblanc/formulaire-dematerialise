# Task 11 — Batch P3: 4 fichiers restants

## Fichiers modifiés

| Fichier | Appels remplacés |
|---------|-----------------|
| `pages/admin_forms.php` | `require_admin()` → `App::auth()->requireAdmin()`, `get_form_owners()` → `App::auth()->getFormOwners()` |
| `pages/admin_settings.php` | `require_admin()` → `App::auth()->requireAdmin()` (+ ajout `use App\Core\App;`) |
| `pages/health.php` | `get_latest_version()` → `App::cache()->getLatestVersion()` (×2, JSON + HTML) |
| `pages/screenshot.php` | `get_auth_user()` → `App::auth()->getUser()` (+ ajout `use App\Core\App;`) |

## Mapping utilisé

| Wrapper | Remplacement |
|---------|-------------|
| `require_admin()` | `App::auth()->requireAdmin()` |
| `get_form_owners($form_id)` | `App::auth()->getFormOwners($form_id)` |
| `get_latest_version()` | `App::cache()->getLatestVersion()` |
| `get_auth_user()` | `App::auth()->getUser()` |

## Résultat lint

```
ok pages/admin_forms.php
ok pages/admin_settings.php
ok pages/health.php
ok pages/screenshot.php
```

## Notes

- `admin_settings.php` et `screenshot.php` n'avaient pas de `use App\Core\App;` — ajouté lors du refactor.
- `health.php` appelle `get_latest_version()` à deux endroits (JSON endpoint + HTML banner) — les deux ont été remplacés.
