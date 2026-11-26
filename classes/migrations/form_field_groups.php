<?php
declare(strict_types=1);

/**
 * Source de vérité unique des groupes de champs (`form_fields.card_group`)
 * livrés par le seed d'installation (`seed_default_forms.php`).
 *
 * Pourquoi un fichier procédural plutôt qu'un enum `src/Enum/` :
 * `classes/migrations/` est chargé par `require_once` depuis
 * `DatabaseMigrations.php`, lui-même inclus par `lib/core_bootstrap.php` avant
 * toute dépendance applicative. Un simple `require_once` garde donc la même
 * disponibilité que le seed, y compris pendant une installation fraîche ou une
 * migration jouée hors contexte HTTP.
 *
 * Consommateurs :
 *  - `seed_default_forms.php` : littéraux `card_group` des lignes seedées ;
 *  - `v47.php` : liste fermée utilisée pour reconnaître une rotation
 *    `card_group`/`hint` (signatures B et C) sans jamais convertir un texte
 *    isolé ;
 *  - `scripts/check_form_field_groups.php` (CI) : contrôle de dérive
 *    bidirectionnel entre cette liste et les littéraux du seed.
 *
 * Les libellés du seed restent écrits en clair ligne par ligne (lisibilité de
 * chaque formulaire). La duplication est donc surveillée, pas silencieuse :
 * tout groupe seedé absent d'ici, ou tout groupe d'ici jamais seedé, fait
 * échouer la garde CI.
 *
 * `Général` est le groupe par défaut (`card_group` vide / `0`) et n'est pas
 * seedé explicitement : il est donc attendu ici sans contrepartie dans le seed.
 *
 * @return list<string>
 */
function default_form_field_card_groups(): array
{
    return [
        'Général',
        'Identité de l\'agent',
        'Informatique (IT)',
        'Ressources Humaines',
        'Logistique',
        'Décision',
        'Agent',
        'Détails de la sortie',
        'Détails de la dépense',
        'Prescription médicale',
        'Matériel demandé',
        'Mutation demandée',
        'Formation demandée',
        'Justification',
        'Accès demandés',
        'Matériel',
    ];
}