// app.js — Handlers externalisés depuis les onclick inline
// Zéro dépendance, vanilla JS
// Chargé via assets.php?type=js&file=app avec nonce CSP
(function() {
  'use strict';

  // ── 1. Copy to clipboard ──────────────────────────────────────
  // Boutons avec data-copy-target="#elementId"
  // Copie le texte de l'élément cible dans le presse-papier
  function copyToClipboard(text, btn, originalLabel) {
    function success() {
      btn.textContent = '✓ Copié !';
      setTimeout(function() { btn.textContent = originalLabel; }, 2000);
    }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      success();
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(success).catch(fallback);
    } else {
      fallback();
    }
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-copy-target]');
    if (!btn) return;
    e.preventDefault();
    var target = document.querySelector(btn.getAttribute('data-copy-target'));
    if (!target) return;
    var originalLabel = btn.textContent;
    copyToClipboard(target.innerText, btn, originalLabel);
  });

  // ── 2. Toggle visibility ──────────────────────────────────────
  // Boutons avec data-toggle="#elementId"
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-toggle]');
    if (!btn) return;
    e.preventDefault();
    var target = document.querySelector(btn.getAttribute('data-toggle'));
    if (target) target.classList.toggle('hidden');
  });

  // ── 3. Dismiss tutorial ───────────────────────────────────────
  // Boutons avec data-dismiss=".selector"
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-dismiss]');
    if (!btn) return;
    e.preventDefault();
    var target = btn.closest(btn.getAttribute('data-dismiss'));
    if (target) target.style.display = 'none';
  });

  // ── 4. Close details ──────────────────────────────────────────
  // Boutons avec data-close-details="details"
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-close-details]');
    if (!btn) return;
    e.preventDefault();
    var details = btn.closest(btn.getAttribute('data-close-details'));
    if (details && details.tagName === 'DETAILS') details.open = false;
  });

  // ── 5. Confirm dialogs ────────────────────────────────────────
  // Boutons avec data-confirm="Message de confirmation"
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-confirm]');
    if (!btn) return;
    if (!confirm(btn.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  // ── 6. Set hidden input value ─────────────────────────────────
  // Boutons avec data-set-input="#inputId=value"
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-set-input]');
    if (!btn) return;
    var parts = btn.getAttribute('data-set-input').split('=');
    if (parts.length === 2) {
      var input = document.querySelector(parts[0]);
      if (input) input.value = parts[1];
    }
  });
})();
