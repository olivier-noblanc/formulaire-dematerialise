<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Politique de la file d'attente SMTP (outbox write-ahead).
 *
 * Source de vérité unique des seuils partagés par les appelants de l'outbox.
 * Aucune dépendance externe — KISS Windows/IIS/SQLite.
 */
final class MailOutbox
{
    /**
     * Délai (secondes) avant une nouvelle tentative de rejeu après un échec
     * SMTP réessayable. La colonne `next_retry_at` d'une ligne `error` est
     * positionnée à `now + BACKOFF_SECONDS`.
     */
    public const int BACKOFF_SECONDS = 900;

    /**
     * Nombre maximal de tentatives d'envoi (envoi initial inclus). Au-delà,
     * une ligne `error` passe en `failed` (échec définitif, plus de rejeu
     * automatique) — elle reste visible pour l'opérateur.
     */
    public const int MAX_ATTEMPTS = 5;

    /**
     * Durée (secondes) après laquelle une ligne `pending` est considérée
     * orpheline (le process qui l'a écrite est mort avant la finalisation).
     * On ne rejoue jamais un `pending` récent pour ne pas doubler un envoi en
     * cours (le timeout SMTP est de 30 s, très inférieur à ce seuil).
     */
    public const int STALE_PENDING_SECONDS = 900;

    /**
     * Durée (secondes) du verrou de revendication : lors d'un rejeu, la ligne
     * revendiquée voit son `next_retry_at` repoussé à `now + LEASE_SECONDS`
     * pour qu'aucun autre worker ne la reprenne pendant l'envoi.
     */
    public const int LEASE_SECONDS = 900;
}
