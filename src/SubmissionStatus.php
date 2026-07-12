<?php

declare(strict_types=1);

namespace App;

/**
 * Statuts de soumission — enum PHP 8.1 avec valeur string pour SQL direct.
 *
 * Usage:
 *   SubmissionStatus::EN_COURS->value   // 'en_cours'
 *   SubmissionStatus::EN_COURS->label() // 'En cours'
 *   SubmissionStatus::EN_COURS->icon()  // '⏳'
 *   SubmissionStatus::EN_COURS->color() // '#f59e0b'
 */
enum SubmissionStatus: string
{
    case EN_COURS = 'en_cours';
    case VALIDE  = 'valide';
    case REFUSE  = 'refuse';
    case ANNULE  = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::EN_COURS => 'En cours',
            self::VALIDE  => 'Validé(e)',
            self::REFUSE  => 'Refusé(e)',
            self::ANNULE  => 'Annulé(e)',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EN_COURS => '⏳',
            self::VALIDE  => '✓',
            self::REFUSE  => '❌',
            self::ANNULE  => '🗑',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EN_COURS => '#f59e0b',
            self::VALIDE  => '#16a34a',
            self::REFUSE  => '#dc2626',
            self::ANNULE  => '#6b7280',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::EN_COURS => 'status-en-cours',
            self::VALIDE  => 'status-valide',
            self::REFUSE  => 'status-refuse',
            self::ANNULE  => 'status-annule',
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
