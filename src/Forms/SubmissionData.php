<?php

declare(strict_types=1);

namespace App\Forms;

use App\Enum\SubmissionField;

/**
 * Helper d'extraction safe des données de soumission (JSON décodé).
 * Évite les warnings PHP 8.5 "Undefined array key".
 */
final class SubmissionData
{
    /**
     * Extrait une chaîne du tableau de données.
     * @param array<string, mixed> $data
     * @param string $default Valeur par défaut si la clé n'existe pas
     */
    public static function get(array $data, SubmissionField $key, string $default = ''): string
    {
        $value = $data[$key->value] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Vérifie si une clé existe et a une valeur non-vide.
     * @param array<string, mixed> $data
     */
    public static function has(array $data, SubmissionField $key): bool
    {
        $value = $data[$key->value] ?? null;
        return !in_array($value, [null, '', '0'], true);
    }
}