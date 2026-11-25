<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Niveau d'urgence d'une échéance (`deadline-days`), source de vérité unique
 * des quatre paliers métier.
 *
 * Les classes CSS (`overdue`, `urgent`, `warning`, `ok`) restent de la
 * présentation et ne sont pas portées par cet enum — seule la classification
 * (seuils 0 / 2 / 5 jours) l'est, via fromDaysRemaining().
 */
enum UrgencyLevel: string
{
    /** Échéance dépassée. */
    case Overdue = 'overdue';
    /** J0 à J-2. */
    case Critical = 'critical';
    /** J-3 à J-5. */
    case Warning = 'warning';
    /** Plus de 5 jours restants. */
    case Ok = 'ok';

    /**
     * Classifie un nombre de jours restants avec les seuils métier historiques :
     * négatif → Overdue, 0-2 → Critical, 3-5 → Warning, au-delà → Ok.
     */
    public static function fromDaysRemaining(int $daysRemaining): self
    {
        if ($daysRemaining < 0) {
            return self::Overdue;
        }
        if ($daysRemaining <= 2) {
            return self::Critical;
        }
        if ($daysRemaining <= 5) {
            return self::Warning;
        }
        return self::Ok;
    }
}