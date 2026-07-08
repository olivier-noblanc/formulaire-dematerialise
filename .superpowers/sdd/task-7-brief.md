# Task 7: Supprimer `lib/html.php`

## Context

`lib/html.php` contient les wrappers HTML (`h()`, `display_user()`, `format_file_size()`, etc.). `h()` a 530+ appelants dans le projet. La suppression de ce fichier est le plus gros chantier.

## Stratégie

Plutôt que de migrer 530+ appels `h()` vers `App::html()->escape()`, on va :
1. Garder `lib/html.php` mais le transformer en facade qui délègue à `App::html()`
2. Supprimer uniquement les fonctions qui ont peu d'appelants
3. Laisser `h()` comme alias de `App::html()->escape()` pour la compatibilité

## Fichiers à modifier

1. `lib/html.php` — transformer en facade minimale
2. `helpers.php` — vérifier le require

## Fonctions à supprimer (peu d'appelants)

| Fonction | Appelants | Action |
|----------|-----------|--------|
| `display_user()` | ~10 | Supprimer (déjà migré dans les pages) |
| `display_user_short()` | ~5 | Supprimer |
| `format_file_size()` | ~5 | Supprimer |
| `get_file_icon()` | ~3 | Supprimer |
| `render_pagination()` | ~3 | Supprimer |
| `render_donut_chart()` | ~3 | Supprimer |
| `t_jargon()` | ~10 | Supprimer (déjà migré dans les pages) |

## Fonction à garder (530+ appelants)

| Fonction | Appelants | Action |
|----------|-----------|--------|
| `h()` | 530+ | Garder comme alias |

## Transformer `lib/html.php`

```php
<?php
// lib/html.php — facade vers HtmlService

/**
 * Alias de App::html()->escape()
 * Gardé pour la compatibilité avec les 530+ appelants restants.
 */
function h(?string $val): string {
    return \App\Core\App::html()->escape($val);
}
```

## Testing

```bash
php -l lib/html.php
```

## Report

Écrire dans `.superpowers/sdd/task-7-report.md`
