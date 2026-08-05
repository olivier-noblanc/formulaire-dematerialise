<div class="form-progress" aria-live="polite">
  <div class="form-progress-header">
    <span class="form-progress-label">Étape <strong id="form-progress-current">0</strong> sur <?= $section_count ?></span>
    <span class="form-progress-count"><span id="form-progress-filled">0</span> / <?= $total_fields ?> champ(s) rempli(s)</span>
  </div>
  <div class="form-progress-bar" role="progressbar"
       aria-valuemin="0" aria-valuemax="<?= $total_fields ?>" aria-valuenow="0"
       aria-label="Progression de la saisie du formulaire" id="form-progress-bar">
    <div class="form-progress-fill w-0" id="form-progress-fill"></div>
  </div>
  <input type="hidden" id="form-progress-total-fields" value="<?= $total_fields ?>">
  <input type="hidden" id="form-progress-section-count" value="<?= $section_count ?>">
</div>
