<?php

declare(strict_types=1);

namespace App\Enum;

enum SubmissionStatus: string
{
    case EnCours = 'en_cours';
    case Valide = 'valide';
    case Refuse = 'refuse';
    case Annule = 'annule';
}
