<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Contrat unique pour `step_recipients.email`.
 *
 * Deux formes sont autorisées :
 *  - une adresse email classique validée par FILTER_VALIDATE_EMAIL ;
 *  - une référence dynamique `{{...}}` (owner, owner_email, field_name, ...).
 *
 * Les valeurs tronquées connues (préfixe local sans domaine) ne sont réparées
 * que si la migration peut confirmer la correspondance structurelle exacte.
 * Aucune autre valeur invalide n'est normalisée : elle bloque l'écriture.
 */
final class RecipientAddress
{
    /**
     * Source de vérité unique du format des références dynamiques.
     *
     * Volontairement identique à l'ancienne regex dupliquée dans
     * AdminRecipientHandler, FormJsonValidator et RecipientResolver : les
     * formats déjà acceptés restent acceptés, rien de plus.
     */
    public const string DYNAMIC_PATTERN = '/^\{\{[a-z][a-z0-9_]*\}\}$/';

    /**
     * Correspondances documentées dans
     * docs/superpowers/specs/2026-10-13-cahier-des-charges-workflow-arrivee-agent.md
     * et dans l'export `.playwright-mcp/gestion-d-une-arrivee-prod.json`.
     *
     * La réparation n'est appliquée que si le libellé de l'étape correspond
     * exactement : une valeur tronquée sur une autre étape est fail-closed.
     *
     * @var array<string, array{email: string, step_label: string}>
     */
    public const array KNOWN_TRUNCATED = [
        'dreets-bfc.communication@' => [
            'email' => 'dreets-bfc.communication@dreets.gouv.fr',
            'step_label' => 'Validation COM',
        ],
        'dreets-bfc.logis@' => [
            'email' => 'dreets-bfc.logis@dreets.gouv.fr',
            'step_label' => 'Validation LOGIS',
        ],
        'dreets-bfc.esic@' => [
            'email' => 'dreets-bfc.esic@dreets.gouv.fr',
            'step_label' => 'Validation ESIC',
        ],
        'dreets-bfc.rh@' => [
            'email' => 'dreets-bfc.rh@dreets.gouv.fr',
            'step_label' => 'Validation RH',
        ],
    ];

    public static function isDynamic(string $value): bool
    {
        return preg_match(self::DYNAMIC_PATTERN, $value) === 1;
    }

    /** Retourne le nom du champ référencé, ou null si le format est invalide. */
    public static function dynamicName(string $value): ?string
    {
        if (preg_match(self::DYNAMIC_PATTERN, $value) !== 1) {
            return null;
        }

        // Le motif n'accepte que "{{nom}}" : le nom est le contenu entre accolades.
        return substr($value, 2, -2);
    }

    public static function isValid(string $value): bool
    {
        return self::isDynamic($value)
            || filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Valide et retourne la valeur trimée, sans réparation implicite.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('step_recipients.email is invalid');
        }

        return $value;
    }

    /**
     * Retourne l'adresse documentée uniquement si la valeur ET le libellé
     * d'étape correspondent exactement. Sinon null (fail-closed).
     */
    public static function knownRepair(string $value, string $stepLabel): ?string
    {
        $known = self::KNOWN_TRUNCATED[trim($value)] ?? null;
        if ($known === null || $known['step_label'] !== $stepLabel) {
            return null;
        }

        return $known['email'];
    }
}