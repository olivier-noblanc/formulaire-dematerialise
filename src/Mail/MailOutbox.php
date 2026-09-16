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

    /**
     * Plafond de rejeux MANUELS opérateur par ligne, en plus du rejeu
     * automatique. Au-delà, `claimFailedForManualReplay()` refuse la
     * revendication : un message bloqué ne peut plus être relancé à la main
     * sans intervention (protection contre le harcèlement du destinataire).
     */
    public const int MANUAL_REPLAY_MAX = 3;

    /**
     * Rétention (jours) du corps HTML des envois en SUCCÈS avant purge RGPD.
     * Seul le corps est purgé (`body_html = NULL`) ; la ligne `mail_log` est
     * CONSERVÉE pour la traçabilité d'envoi.
     */
    public const int BODY_KEEP_SENT_DAYS = 7;

    /**
     * Rétention (jours) du corps HTML des échecs TERMINAUX (`error`, `failed`,
     * `blocked`) avant purge RGPD. Marge plus longue que les succès pour
     * laisser à l'opérateur le temps de diagnostiquer puis rejouer le message.
     */
    public const int BODY_KEEP_TERMINAL_DAYS = 30;
}
