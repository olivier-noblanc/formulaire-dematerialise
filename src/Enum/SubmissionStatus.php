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
        return match ($this) {
            self::EnCours => 'En cours',
            self::Valide => 'Validé',
            self::Refuse => 'Refusé',
            self::Annule => 'Annulé',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EnCours => '⏳',
            self::Valide => '✓',
            self::Refuse => '❌',
            self::Annule => '🗑',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EnCours => '#f59e0b',
            self::Valide => '#16a34a',
            self::Refuse => '#dc2626',
            self::Annule => '#6b7280',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::EnCours => 'badge-warn',
            self::Valide => 'badge-ok',
            self::Refuse => 'badge-err',
            self::Annule => 'badge-annule',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::EnCours => 'status-en-cours',
            self::Valide => 'status-valide',
            self::Refuse => 'status-refuse',
            self::Annule => 'status-annule',
        };
    }

    /**
     * Crée un statut depuis une valeur string (retourne null si invalide).
     */
    public static function fromValue(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
