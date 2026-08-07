<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Champs standards des données de soumission (JSON décodé).
 * Liste fermée - règle AGENTS.md #12 : pas de magic strings.
 */
enum SubmissionField: string
{
    case PRENOM = 'prenom';
    case NOM = 'nom';
    case AFFECTATION = 'affectation';
    case VALIDATIONS = 'validations';
}