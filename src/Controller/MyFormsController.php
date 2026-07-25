<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page "Mes formulaires" pour les owners.
 */
final class MyFormsController extends BaseController
{
    public function handle(): void
    {
        $user = App::auth()->getUser();
        if ($user === '') {
            new \App\Render\ErrorRenderer()->errorPage(403, 'Accès refusé', 'Vous devez être connecté.', '');
        }

        $ownedForms = App::auth()->getOwnedForms($user);

        ob_start();
        ?>
  <h1><span aria-hidden="true">📊</span> Mes formulaires</h1>

  <?php if ($ownedForms === []): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📋</div>
      <p>Vous n'êtes propriétaire d'aucun formulaire.</p>
      <p style="font-size:.85rem;color:#555;">Contactez un administrateur pour devenir propriétaire d'un formulaire.</p>
    </div>
  <?php else: ?>
    <p style="margin-bottom:1.5rem;color:#555;font-size:.9rem;">
      Vous êtes propriétaire de <?= count($ownedForms) ?> formulaire(s). Cliquez sur un formulaire pour voir le suivi des demandes.
    </p>
    <div class="form-cards">
      <?php foreach ($ownedForms as $ownedForm):
          $f_id    = \App\Core\App::html()->escape((string) ($ownedForm['id'] ?? ''));
          $f_label = \App\Core\App::html()->escape(App::html()->tJargon((string) ($ownedForm['label'] ?? '')));
          $f_desc  = '';
          if (!empty($ownedForm['description'])) {
              $f_desc = '<div class="fc-desc">' . \App\Core\App::html()->escape(App::html()->tJargon((string) $ownedForm['description'])) . '</div>';
          }
          ?>
        <a href="index.php?p=form_tracking&f=<?= $f_id ?>" class="form-card">
          <div class="fc-title"><?= $f_label ?></div>
          <?= $f_desc ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php
        $content = (string) ob_get_clean();
        echo $this->renderPage('Mes formulaires', 'mes_formulaires', '', $content);
    }
}
