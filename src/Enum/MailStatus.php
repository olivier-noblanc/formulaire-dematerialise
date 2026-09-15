<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Statuts de la table mail_log (outbox SMTP write-ahead durable).
 *
 * Source de vérité unique pour la liste fermée des statuts d'envoi :
 *  - Pending : ligne écrite AVANT l'appel SMTP (write-ahead). Si le process
 *              meurt entre l'INSERT et la mise à jour finale, la ligne reste
 *              `pending` et sera reprise par le worker de rejeu.
 *  - Sent    : SMTP a accepté le message.
 *  - Error   : échec d'envoi RÉESSAYABLE (SMTP injoignable, timeout...).
 *  - Failed  : échec définitif après épuisement du plafond de tentatives.
 *  - Blocked : envoi impossible sans intervention (adresse invalide,
 *              configuration SMTP absente) — jamais réessayé automatiquement.
 *  - DryRun  : envoi simulé (mail_dry_run=1), aucun SMTP contacté.
 */
enum MailStatus: string
{
    case Pending = 'pending';
    case Sent    = 'sent';
    case Error   = 'error';
    case Failed  = 'failed';
    case Blocked = 'blocked';
    case DryRun  = 'dry_run';

    /**
     * Liste des valeurs valides (pour le CHECK SQL et la validation d'entrée).
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

    }