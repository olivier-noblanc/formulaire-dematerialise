<div class="toolbar">
  <div class="toolbar-filters">
    <form method="GET" class="u-ali-dis-gap">
      <input type="hidden" name="statut" value="<?= $filtre_h ?>">
      <label for="filter-form" class="sr-only">Filtrer par formulaire</label>
      <select name="form" id="filter-form" class="form-filter">
        <option value="">Tous les formulaires</option>
        <?= $options ?>
      </select>
      <button type="submit" class="btn-admin btn-sm-9">OK</button>
    </form>
    <?= $search_bar ?>
  </div>
  <nav class="admin-actions" aria-label="Actions d'administration">
    <div class="admin-actions-row">
      <span class="admin-actions-label">Actions principales</span>
      <div class="admin-actions-btns" role="group" aria-label="Actions principales">
        <a href="index.php?p=admin_forms" class="btn-admin" aria-label="Gérer les formulaires">
          <span aria-hidden="true">⚙</span> Formulaires
        </a>
        <a href="index.php?p=admin_alerts" class="btn-admin" aria-label="Configurer les alertes automatiques">
          <span aria-hidden="true">🔔</span> Alertes
        </a>
      </div>
    </div>
    <div class="admin-actions-row">
      <span class="admin-actions-label">Consultation</span>
      <div class="admin-actions-btns" role="group" aria-label="Consultation">
        <a href="index.php?p=monitoring" class="btn-admin btn-admin--secondary" aria-label="Surveillance du système en temps réel">
          <span aria-hidden="true">🖥</span> Surveillance
        </a>
        <a href="index.php?p=stats" class="btn-admin btn-admin--secondary" aria-label="Consulter les statistiques d'utilisation">
          <span aria-hidden="true">📊</span> Statistiques
        </a>
      </div>
    </div>
    <div class="admin-actions-row admin-actions-advanced">
      <span class="admin-actions-label">Actions avancées <span class="admin-actions-label-hint">— à utiliser ponctuellement</span></span>
      <div class="admin-actions-btns" role="group" aria-label="Actions avancées (export et protection des données)">
        <a href="index.php?p=dashboard&export=csv&statut=<?= $filtre_h ?>&form=<?= $form_h ?>&search=<?= $search_h ?>" class="btn-admin btn-admin--tertiary" aria-label="Exporter les soumissions filtrées au format CSV">
          <span aria-hidden="true">📥</span> Export CSV
        </a>
        <a href="index.php?p=rgpd" class="btn-admin btn-admin--danger" aria-label="Gérer la protection des données (RGPD) et la purge">
          <span aria-hidden="true">🔐</span> Protection des données
        </a>
      </div>
    </div>
  </nav>
</div>
