<?php

declare(strict_types=1);

namespace App\Enum;

enum SubmissionStatus: string
{
    case EnCours = 'en_cours';
    case Valide = 'valide';
    case Refuse = 'refuse';
    case Annule = 'annule';

    public function label(): string
    {
        return match($this) {
            self::EnCours => 'En cours',
            self::Valide => 'Validé',
            self::Refuse => 'Refusé',
            self::Annule => 'Annulé',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::EnCours => 'badge-warn',
            self::Valide => 'badge-ok',
            self::Refuse => 'badge-err',
            self::Annule => 'badge-annule',
        };
    }
}
