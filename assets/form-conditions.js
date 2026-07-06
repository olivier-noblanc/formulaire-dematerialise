// Affichage conditionnel des champs de formulaire
// Lit data-condition sur les div.field et masque/affiche selon les valeurs
(function() {
  var form = document.getElementById('form-main');
  if (!form) return;

  var conditionalDivs = form.querySelectorAll('[data-condition]');
  if (conditionalDivs.length === 0) return;

  function getFieldValue(fieldName) {
    var el = form.querySelector('[name="' + fieldName + '"]');
    if (!el) return '';
    if (el.type === 'checkbox') return el.checked ? 'true' : '';
    if (el.type === 'select-one') return el.value;
    return (el.value || '').trim();
  }

  function evaluateCondition(condJson) {
    try {
      var cond = JSON.parse(condJson);
      if (!cond.field) return true;
      var actual = getFieldValue(cond.field);
      var expected = cond.value;
      switch (cond.op) {
        case 'eq': return actual === expected;
        case 'neq': return actual !== expected;
        case 'in': return Array.isArray(expected) ? expected.indexOf(actual) >= 0 : actual === expected;
        case 'not_empty': return actual !== '';
        case 'empty': return actual === '';
        default: return true;
      }
    } catch(e) { return true; }
  }

  function updateAll() {
    conditionalDivs.forEach(function(div) {
      var cond = div.getAttribute('data-condition');
      var visible = evaluateCondition(cond);
      div.style.display = visible ? '' : 'none';
      // Retirer/ajouter required selon la visibilité
      var inputs = div.querySelectorAll('input, select, textarea');
      inputs.forEach(function(input) {
        if (visible) {
          // Restaurer required si le champ en avait un
          if (input.getAttribute('data-was-required') === '1') {
            input.setAttribute('required', 'required');
            input.setAttribute('aria-required', 'true');
          }
        } else {
          // Sauvegarder et retirer required
          if (input.hasAttribute('required')) {
            input.setAttribute('data-was-required', '1');
            input.removeAttribute('required');
            input.removeAttribute('aria-required');
          }
        }
      });
    });
  }

  // Écouter les changements sur tous les champs du formulaire
  form.addEventListener('input', updateAll);
  form.addEventListener('change', updateAll);
  updateAll();
})();
