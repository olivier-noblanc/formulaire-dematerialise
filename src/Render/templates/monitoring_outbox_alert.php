<?php
/**
 * Bannière admin rouge — emails en échec définitif dans l'outbox SMTP.
 *
 * `$failed_count` est un entier (jamais de contenu d'email ici). Rendu
 * uniquement si le compte est > 0 (gate côté MonitoringRenderer::outboxAlert).
 *
 * @var int $failed_count
 */
?>
<div class="outbox-alert" role="alert">
    <strong><span aria-hidden="true">⚠</span> <?= (int) $failed_count ?> email(s) n'ont pas pu être envoyés définitivement.</strong>
    Consultez le journal des emails ci-dessous, vérifiez la configuration SMTP,
    puis corrigez la cause avant de relancer un envoi.
</div>