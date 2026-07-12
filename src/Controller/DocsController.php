<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Aide et documentation.
 *
 * Wrapper OOP autour de lib/docs_sections.php (DocumentationService).
 * Page publique — aucune auth requise, mais affiche le statut admin si connecté.
 */
final class DocsController extends BaseController
{
    public function handle(): void
    {


        $is_logged_in = false;
        $is_admin     = false;
        $user_email   = '';

        try {
            $user_email = $this->auth->getUser();
            $is_logged_in = !empty($user_email);
            $is_admin = $this->auth->isAdminEffective();
        } catch (\RuntimeException $e) {
            $is_logged_in = false;
            error_log('docs.php auth error: ' . $e->getMessage());
        }

        $legal_mentions = '';
        try {
            $legal_mentions = $this->settings->get('legal_mentions', '');
        } catch (\Exception $e) {
            $legal_mentions = '';
            error_log('docs.php legal_mentions error: ' . $e->getMessage());
        }

        $pageCss = '';
        ob_start();
        ?>
  <h1>Aide et documentation</h1>
  <p class="subtitle"><span class="version-badge">v<?= \App\Core\App::html()->escape($this->cache->getLatestVersion()) ?></span></p>

<?php $_docs = \App\Core\App::getInstance()->get(\App\Docs\DocumentationService::class); ?>

<?= $_docs->renderStart() ?>

<?= $_docs->renderToc() ?>

  <details class="full-doc">
    <summary><span aria-hidden="true">📖</span> Documentation complète (avancée)</summary>
    <div class="full-doc-body">

<?= $_docs->renderQuickstart() ?>

<?= $_docs->renderAgent() ?>

<?= $_docs->renderValidateur() ?>

<?= $_docs->renderAdmin() ?>

<?= $_docs->renderFeatures() ?>

<?= $_docs->renderRoles() ?>

<?= $_docs->renderFaq() ?>

<?= $_docs->renderRgpd() ?>

<?= $_docs->renderTechnique() ?>

    </div>
  </details>

</div>

<a href="#top" class="back-to-top" title="Retour en haut de page">↑</a>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Documentation', 'docs', $pageCss, $content);
    }
}
