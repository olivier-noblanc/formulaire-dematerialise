// Compteur de champs remplis — formulaire multi-sections (U-08)
// Zéro dépendance, vanilla JS
(function() {
  var form = document.getElementById('form-main');
  if (!form) return;
  var fillEl = document.getElementById('form-progress-fill');
  var filledEl = document.getElementById('form-progress-filled');
  var currentEl = document.getElementById('form-progress-current');
  var barEl = document.getElementById('form-progress-bar');
  if (!fillEl || !filledEl) return;
  var totalEl = document.getElementById('form-progress-total-fields');
  var sectionEl = document.getElementById('form-progress-section-count');
  var total = totalEl ? parseInt(totalEl.value, 10) : 0;
  var fieldsets = form.querySelectorAll('fieldset.card');
  function isFilled(input) {
    if (input.type === 'checkbox') return input.checked;
    if (input.type === 'file') return false;
    if (input.tagName === 'SELECT') return input.value !== '';
    return (input.value || '').trim() !== '';
  }
  function update() {
    var inputs = form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="file"]):not([type="checkbox"]), textarea, select');
    var filled = 0;
    for (var i = 0; i < inputs.length; i++) {
      if (isFilled(inputs[i])) filled++;
    }
    var pct = total > 0 ? Math.round((filled / total) * 100) : 0;
    // CSP-safe: use CSS class instead of inline style.width
    // 101 classes (.progress-0 to .progress-100) are pre-generated in style_utility.css
    fillEl.className = 'progress-' + pct;
    filledEl.textContent = filled;
    if (barEl) barEl.setAttribute('aria-valuenow', String(filled));
    if (currentEl && fieldsets.length > 1) {
      var started = 0;
      for (var j = 0; j < fieldsets.length; j++) {
        var fsInputs = fieldsets[j].querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="file"]), textarea, select');
        for (var k = 0; k < fsInputs.length; k++) {
          if (isFilled(fsInputs[k])) { started++; break; }
        }
      }
      currentEl.textContent = String(started);
    }
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
})();
