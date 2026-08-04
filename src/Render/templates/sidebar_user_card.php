<?php
/**
 * Template: sidebar user card with persona support.
 * Variables: $is_admin, $displayed_initials, $displayed_user_short,
 *            $displayed_user_title, $persona_active_email, $user_card_data_persona,
 *            $user_card_data_active, $user_card_data_csrf
 * @var bool $is_admin
 * @var string $displayed_initials
 * @var string $displayed_user_short
 * @var string $displayed_user_title
 * @var string $persona_active_email
 * @var string $user_card_data_persona
 * @var string $user_card_data_active
 * @var string $user_card_data_csrf
 */
declare(strict_types=1);
?>
<div class="sidebar-user">
  <div class="sidebar-user-card<?= $is_admin ? ' sidebar-user-card-admin' : '' ?>"
       id="sidebar-user-card" tabindex="0" role="button"
       aria-label="<?= $is_admin ? 'Cliquer pour changer de persona' : 'Utilisateur connecté' ?>"
       title="<?= \App\Core\App::html()->escape($displayed_user_title) ?>"
       <?= $user_card_data_persona ?><?= $user_card_data_active ?><?= $user_card_data_csrf ?>>
    <span class="sidebar-user-avatar<?= $persona_active_email !== '' ? ' persona-active' : '' ?>"><?= $displayed_initials ?></span>
    <span class="sidebar-user-email"><?= \App\Core\App::html()->escape($displayed_user_short) ?></span>
    <?= $is_admin ? '<span class="sidebar-user-chevron" aria-hidden="true">▾</span>' : '' ?>
  </div>
</div>
