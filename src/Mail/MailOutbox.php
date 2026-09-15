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
}
