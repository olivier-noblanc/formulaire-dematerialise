<?php
/**
 * Template: persona dropdown JavaScript (inserted in footer).
 * Uses data attributes from #sidebar-user-card element.
 * @var string $script_nonce
 */
declare(strict_types=1);
?>
<script nonce="<?= $script_nonce ?>">
(function() {
  var card = document.getElementById('sidebar-user-card');
  if (!card || !card.classList.contains('sidebar-user-card-admin')) return;

  var dropdown = document.createElement('div');
  dropdown.className = 'sidebar-persona-dropdown';
  dropdown.id = 'sidebar-persona-dropdown';

  var selfEmail = card.getAttribute('data-persona-self') || '';
  var activeEmail = card.getAttribute('data-persona-active') || '';
  var csrfToken = card.getAttribute('data-csrf-token') || '';

  function createPersonaForm(action, email, token) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php?p=persona&action=' + action;
    var fields = {csrf_token: csrfToken};
    if (email) fields.email = email;
    if (token) fields.persona_token = token;
    for (var k in fields) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = k;
      inp.value = fields[k];
      form.appendChild(inp);
    }
    return form;
  }

  var html = '<div class="sidebar-persona-dropdown-header">🎭 Changer de rôle</div>';
  if (activeEmail) {
    var stopLink = document.createElement('a');
    stopLink.className = 'sidebar-persona-option-reset';
    stopLink.href = '#';
    stopLink.textContent = '✕ Revenir en mode admin';
    stopLink.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      var form = createPersonaForm('stop', '', new URLSearchParams(window.location.search).get('persona_token') || '');
      document.body.appendChild(form);
      form.submit();
    });
    dropdown.innerHTML = html;
    dropdown.appendChild(stopLink);
  } else if (selfEmail) {
    var startLink = document.createElement('a');
    startLink.className = 'sidebar-persona-option';
    startLink.href = '#';
    startLink.innerHTML = '<span class="u-fon-mar-3">👤</span> Vue agent'
          + '<div class="hint-muted-2">Visualiser l\'interface avec des droits réduits</div>';
    startLink.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      var form = createPersonaForm('start', selfEmail, '');
      document.body.appendChild(form);
      form.submit();
    });
    dropdown.innerHTML = html;
    dropdown.appendChild(startLink);
  } else {
    dropdown.innerHTML = html + '<div class="btn-sm">Mode admin uniquement</div>';
  }
  card.appendChild(dropdown);

  card.addEventListener('click', function(e) {
    e.stopPropagation();
    dropdown.classList.toggle('open');
  });
  document.addEventListener('click', function(e) {
    if (!card.contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });
  card.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      dropdown.classList.toggle('open');
    }
    if (e.key === 'Escape') {
      dropdown.classList.remove('open');
    }
  });
})();
</script>
