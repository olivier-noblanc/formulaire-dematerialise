<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Contrat unique pour les colonnes d'ordre (`form_fields.ordre`, `steps.ordre`).
 *
 * SQLite applique l'affinité INTEGER, donc une chaîne numérique envoyée par un
 * formulaire HTML est légitimement coercible. En revanche une valeur vide,
 * négative, décimale ou non numérique doit être refusée avant l'écriture.
 */
final class OrderValue
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function assertValid(mixed $value, string $column = 'ordre', int $minimum = 0): int
    {
        if (is_int($value)) {
            $ordre = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $ordre = (int) $value;
        } else {
            throw new \InvalidArgumentException("{$column} must be a non-negative integer");
        }

        if ($ordre < $minimum) {
            throw new \InvalidArgumentException("{$column} must be >= {$minimum}");
        }

        return $ordre;
    }
}