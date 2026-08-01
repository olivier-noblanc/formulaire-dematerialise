<!-- Toggle LDAP/SMTP config visibility -->
<script __CSP_NONCE_PLACEHOLDER__>
// Note : ce script minimal est le seul JS de la page — il gère uniquement
// l'affichage/masquage conditionnel des blocs LDAP/SMTP dans le formulaire admin.
// L'application fonctionne parfaitement sans JS (les sections sont toutes visibles
// par défaut et le serveur ignore les champs non pertinents).
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('email_verify_mode');
    var ldapBlock = document.getElementById('ldap-config');
    var smtpBlock = document.getElementById('smtp-info');

    function toggle() {
        if (!sel) return;
        var val = sel.value;
        if (ldapBlock) ldapBlock.style.display = (val === 'ldap') ? '' : 'none';
        if (smtpBlock) smtpBlock.style.display = (val === 'smtp') ? '' : 'none';
    }

    if (sel) {
        sel.addEventListener('change', toggle);
        toggle();
    }
});
</script>

<script __CSP_NONCE_PLACEHOLDER__>
// Highlight active anchor in nav on scroll
document.addEventListener('DOMContentLoaded', function() {
  var nav = document.querySelector('.anchor-nav');
  if (!nav) return;
  var links = nav.querySelectorAll('a');
  var sections = [];
  links.forEach(function(link) {
    var id = link.getAttribute('href').replace('#', '');
    var el = document.getElementById(id);
    if (el) sections.push({el: el, link: link});
  });
  function update() {
    var scrollTop = window.scrollY + 80;
    var active = sections[0];
    for (var i = 0; i < sections.length; i++) {
      if (sections[i].el.offsetTop <= scrollTop) active = sections[i];
    }
    links.forEach(function(l) { l.classList.remove('active'); });
    if (active) active.link.classList.add('active');
  }
  window.addEventListener('scroll', update, {passive: true});
  update();
});
</script>
