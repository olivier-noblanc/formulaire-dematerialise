<aside class="system-overview" aria-label="État du système">
  <span class="system-overview-title">État du système</span>
  <span class="system-overview-item" title="Connexion SMTP au serveur <?= $smtp_host ?>:<?= $smtp_port ?>">
    <?= $smtp_dot ?> SMTP : <strong><?= $smtp_label ?></strong>
  </span>
  <span class="system-overview-item" title="Base de données SQLite accessible en lecture/écriture">
    🟢 DB : <strong>OK</strong>
  </span>
  <span class="system-overview-item" title="Date du dernier téléchargement ou restauration de sauvegarde">
    📅 Dernière sauvegarde : <strong><?= $last_backup ?></strong>
  </span>
  <span class="system-overview-item" title="Demandes en cours de validation">
    📊 Demandes en attente : <strong><?= $en_cours ?></strong>
  </span>
  <span class="system-overview-links">
    <a href="index.php?p=health" aria-label="Voir les détails de l'état du système">Détails</a>
    <a href="index.php?p=monitoring" aria-label="Aller à la surveillance du système">Surveillance</a>
  </span>
</aside>
