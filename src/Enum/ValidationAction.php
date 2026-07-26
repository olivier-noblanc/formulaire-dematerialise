<?php

declare(strict_types=1);

namespace App\Enum;

enum ValidationAction: string
{
    case Valider = 'valider';
    case Refuser = 'refuser';
    /**
     * Annulation par l'agent ou un admin (TokenService::cancel()).
     *
     * CS-04 (audit 2026-07-26) : avant, cancel() enregistrait 'refuser'
     * dans validations[] pour signaler l'annulation. Cela mélangeait la
     * sémantique admin (l'agent a annulé sa propre demande) avec la
     * sémantique validateur (un validateur a refusé la demande).
     * L'enum distinct permet aux UI et aux stats de distinguer les deux
     * cas sans avoir à inspecter le step_label ('Annulation').
     */
    case Annule = 'annule';
}
