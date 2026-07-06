<?php
declare(strict_types=1);

/**
 * pages/my_forms.php — Page "Mes formulaires" pour les owners.
 *
 * v10.1.9 — Page dédiée (pas sur l'accueil). Liste les formulaires dont
 * l'utilisateur est propriétaire, avec un lien vers form_tracking pour chacun.
 *
 * Accessible aux owners (admin ou non). Si l'user n'est owner d'aucun
 * formulaire, affiche un message "Vous n'êtes propriétaire d'aucun formulaire".
 */

require_once dirname(__DIR__) . '/helpers.php';

$user = get_auth_user();
if ($user === '') {
    render_error_page(403, 'Accès refusé', 'Vous devez être connecté.', '');
}

$owned_forms = get_owned_forms($user);

$page_css = '';
ob_start();
?>
  <h1><span aria-hidden="true">📊</span> Mes formulaires</h1>

  <?php if (empty($owned_forms)): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📋</div>
      <p>Vous n'êtes propriétaire d'aucun formulaire.</p>
      <p style="font-size:.85rem;color:#555;">Contactez un administrateur pour devenir propriétaire d'un formulaire.</p>
    </div>
  <?php else: ?>
    <p style="margin-bottom:1.5rem;color:#555;font-size:.9rem;">
      Vous êtes propriétaire de <?= count($owned_forms) ?> formulaire(s). Cliquez sur un formulaire pour voir le suivi des demandes.
    </p>
    <div class="form-cards">
      <?php foreach ($owned_forms as $of):
        $f_id    = h((string)($of['id'] ?? ''));
        $f_label = h(t_jargon((string)($of['label'] ?? '')));
        $f_desc  = '';
        if (!empty($of['description'])) {
          $f_desc = '<div class="fc-desc">' . h(t_jargon((string)$of['description'])) . '</div>';
        }
      ?>
        <a href="index.php?p=form_tracking&f=<?= $f_id ?>" class="form-card">
          <div class="fc-title"><?= $f_label ?></div>
          <?= $f_desc ?>
          <div class="fc-btn">Voir le suivi →</div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php
$content = (string)ob_get_clean();
echo render_page('Mes formulaires', 'my_forms', $page_css, $content);
