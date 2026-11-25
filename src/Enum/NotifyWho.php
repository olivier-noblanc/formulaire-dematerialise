<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Destinataire d'une règle d'alerte (`alert_rules.notify_who`).
 *
 * Source de vérité unique pour la liste fermée des cinq sentinelles. Une
 * adresse email personnalisée reste une valeur dynamique : elle n'appartient
 * pas à cet enum et doit être validée par FILTER_VALIDATE_EMAIL (voir
 * AlertRepository::isValidNotifyWho()).
 */
enum NotifyWho: string
{
    case Admin               = 'admin';
    case Submitter           = 'submitter';
    case Validators          = 'validators';
    case AdminAndSubmitter   = 'admin+submitter';
    case AdminAndValidators  = 'admin+validators';

    /**
     * Option d'interface « courriel personnalisé ». Jamais stockée : le
     * contrôleur la résout en adresse email validée avant écriture.
     */
    public const string CUSTOM_OPTION = 'custom';

    /**
     * Liste des sentinelles valides (CHECK SQL, formulaires, validation).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases()
        );
    }

    /**
     * Vrai si la valeur est l'une des cinq sentinelles (pas un email).
     */
    public static function isSentinel(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /**
     * Libellé affiché dans les formulaires et badges admin.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin              => 'Administrateurs',
            self::Submitter          => 'Agent (demandeur)',
            self::Validators         => 'Validateurs en cours',
            self::AdminAndSubmitter  => 'Admins + Agent',
            self::AdminAndValidators => 'Admins + Validateurs',
        };
    }
}