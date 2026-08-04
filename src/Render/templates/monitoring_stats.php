<?php
$avg_label = $avg_days > 0 ? $avg_days . ' j' : $avg_hours . ' h';
$alert_cls = $active_alerts === '' || $active_alerts === null || $active_alerts === '0' ? 'success' : 'danger';
$nb_tokens_bloques = count($tokens_bloques);
$nb_active_alerts  = count($active_alerts);
?>
<!-- Stats globales -->
<div class="grid-3">
  <div class="stat-card">
    <div class="stat-value"><?= $total_sub ?></div>
    <div class="stat-label">Soumissions totales</div>
  </div>
  <div class="stat-card success">
    <div class="stat-value"><?= $taux_validation ?>%</div>
    <div class="stat-label">Taux de validation</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $avg_label ?></div>
    <div class="stat-label">Temps moyen de traitement</div>
  </div>
  <div class="stat-card warning">
    <div class="stat-value"><?= $en_cours_sub ?></div>
    <div class="stat-label">En cours</div>
  </div>
  <div class="stat-card danger">
    <div class="stat-value"><?= $nb_tokens_bloques ?></div>
    <div class="stat-label">Tokens bloqués</div>
  </div>
  <div class="stat-card <?= $alert_cls ?>">
    <div class="stat-value"><?= $nb_active_alerts ?></div>
    <div class="stat-label">Alertes actives</div>
  </div>
</div>
