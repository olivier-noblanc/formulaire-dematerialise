<?php
/**
 * Bannière admin — état de la file d'envoi INCONNU.
 *
 * F6 : affichée quand MailService::getOutboxFailureCount() retourne null
 * (compteur illisible : table absente ou erreur d'accès). On ne peut pas
 * confirmer l'absence d'échec définitif — aucun détail interne n'est exposé
 * (ni chemin, ni message d'exception).
 */
?>
<div class="outbox-alert outbox-alert--unknown" role="alert">
    <strong><span aria-hidden="true">⚠</span> État de la file d'envoi des emails inconnu.</strong>
    Le compteur des échecs définitifs n'a pas pu être lu. Vérifiez la base de données
    et la page de santé avant de conclure.
</div>