<?php
require_once dirname(__DIR__) . '/helpers.php';

// Modules de sections de documentation (P-DOCS refactor)
require_once dirname(__DIR__) . '/lib/docs_section_start.php';
require_once dirname(__DIR__) . '/lib/docs_section_toc.php';
require_once dirname(__DIR__) . '/lib/docs_section_quickstart.php';
require_once dirname(__DIR__) . '/lib/docs_section_agent.php';
require_once dirname(__DIR__) . '/lib/docs_section_validateur.php';
require_once dirname(__DIR__) . '/lib/docs_section_admin.php';
require_once dirname(__DIR__) . '/lib/docs_section_features.php';
require_once dirname(__DIR__) . '/lib/docs_section_roles.php';
require_once dirname(__DIR__) . '/lib/docs_section_faq.php';
require_once dirname(__DIR__) . '/lib/docs_section_rgpd.php';
require_once dirname(__DIR__) . '/lib/docs_section_technique.php';


// ITER1-A (Lead Designer) — Refonte UX pour M. Robert (70 ans, non technicien).
//   - Section « Pour commencer » en haut de page avec 4 cartes simples :
//     Comment faire une demande / Comment valider / Où voir mes demandes / Besoin d'aide.
//   - Sommaire en haut avec ancres vers les sections.
//   - Documentation technique détaillée (énorme) repliée dans un <details> fermé
//     par défaut (summary « Documentation complète (avancée) »).
//   - Polices >= 14px sur tout le contenu visible.
//   - Icônes Marianne-style (emojis) pour chaque section.
//   - t_jargon() appliqué sur les textes visibles (cartes « Pour commencer » + sommaire).

// Determine auth status — this page is accessible to everyone
$is_logged_in = false;
$is_admin     = false;
$user_email   = '';

try {
    $user_email = get_auth_user();
    $is_logged_in = !empty($user_email);
    $is_admin = is_admin_effective();  // v9.9.0 — persona: false si admin en mode visu
} catch (RuntimeException $e) {
    // AUTH_USER missing — unauthenticated context (e.g. token link)
    $is_logged_in = false;
    error_log('docs.php auth error: ' . $e->getMessage());
}

// Récupérer les mentions légales pour la section RGPD
$legal_mentions = '';
try {
    $legal_mentions = \App\Core\App::settings()->get('legal_mentions', '');
} catch (Exception $e) {
    $legal_mentions = '';
    error_log('docs.php legal_mentions error: ' . $e->getMessage());
}
?>
<?php
$page_css = '';
ob_start();
?>
  <h1>Aide et documentation</h1>
  <p class="subtitle"><span class="version-badge">v<?= h(get_latest_version()) ?></span></p>
  <?php // v10.0.6 — subtitle raccourci (gardé seulement la version, utile) ?>

<?= render_docs_section_start() ?>

<?= render_docs_section_toc() ?>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- ITER1-A — Documentation complète (avancée) dans un <details> -->
  <!-- M. Robert voit « Pour commencer » + le sommaire. Le reste,    -->
  <!-- plus technique, est replié par défaut.                       -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <details class="full-doc">
    <summary><span aria-hidden="true">📖</span> Documentation complète (avancée)</summary>
    <div class="full-doc-body">

<?= render_docs_section_quickstart() ?>

<?= render_docs_section_agent() ?>

<?= render_docs_section_validateur() ?>

<?= render_docs_section_admin() ?>

<?= render_docs_section_features() ?>

<?= render_docs_section_roles() ?>

<?= render_docs_section_faq() ?>

<?= render_docs_section_rgpd() ?>

<?= render_docs_section_technique() ?>

  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- ITER1-A — Fin de la « Documentation complète (avancée) »    -->
  <!-- (fermeture du <details class="full-doc"> ouvert plus haut)   -->
  <!-- ═══════════════════════════════════════════════════════════ -->
    </div><!-- /.full-doc-body -->
  </details><!-- /.full-doc -->

</div>

<a href="#top" class="back-to-top" title="Retour en haut de page">↑</a>
<?php
$content = (string)ob_get_clean();
echo render_page('Documentation', 'docs', $page_css, $content);
