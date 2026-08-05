<?php
/**
 * @var bool   $can_edit
 * @var string $admin_comment
 * @var string $sub_id
 */
if (!$can_edit) {
    return '';
}

$comment_h   = \App\Core\App::html()->escape($admin_comment);
$sub_id_h    = \App\Core\App::html()->escape($sub_id);
$csrf        = \App\Core\App::security()->csrfField();

return <<<HTML
      <!-- Commentaire admin -->
      <div class="card u-bor-4" id="admin-comment">
        <h2><span aria-hidden="true">💬</span> Commentaire (admin / propriétaire)</h2>
        <p class="hint mb-1-2">Annotation libre post-soumission, indépendante des champs validateur. Visible uniquement par les administrateurs et propriétaires du formulaire.</p>
        <form method="POST" class="flex-col-gap5">
          {$csrf}
          <input type="hidden" name="action" value="update_admin_comment">
          <input type="hidden" name="sub_id" value="{$sub_id_h}">
          <label for="admin_comment" class="sr-only">Commentaire</label>
          <textarea name="admin_comment" id="admin_comment" rows="4" placeholder="Ajouter une note, un suivi, un contexte de clôture..." class="input-filter">{$comment_h}</textarea>
          <div>
            <button type="submit" class="btn btn-secondary btn-sm-11"><span aria-hidden="true">💾</span> Enregistrer le commentaire</button>
          </div>
        </form>
      </div>
    HTML;
