<?php
/**
 * Template: sidebar navigation links.
 * Variables: $current_page, $main_links, $admin_links, $extra_admin_links,
 *            $is_admin_eff, $owned_forms, $my_en_cours_count, $pending_count
 * @var string $current_page
 * @var array<string, array{href: string, label: string, icon: string}> $main_links
 * @var array<string, array{href: string, label: string, icon: string}> $admin_links
 * @var array<string, array{href: string, label: string, icon: string}> $extra_admin_links
 * @var bool $is_admin_eff
 * @var array<int, mixed> $owned_forms
 * @var int $my_en_cours_count
 * @var int $pending_count
 */
declare(strict_types=1);
?>
<div class="sidebar-section-title">Navigation</div>
<?php foreach ($main_links as $key => $link): ?>
<?php
    $active_cls = ($current_page === $key) ? ' active' : '';
    $badge = '';
    if ($key === 'mes_demandes' && $my_en_cours_count > 0) {
        $badge = '<span class="sidebar-badge" aria-label="' . $my_en_cours_count . ' en cours">' . $my_en_cours_count . '</span>';
    }
    if ($key === 'mes_validations' && $pending_count > 0) {
        $badge = '<span class="sidebar-badge" aria-label="' . $pending_count . ' en attente">' . $pending_count . '</span>';
    }
    ?>
<a href="<?= $link['href'] ?>" class="sidebar-item<?= $active_cls ?>">
  <span class="sidebar-item-icon" aria-hidden="true"><?= $link['icon'] ?></span>
  <span class="sidebar-item-label"><?= $link['label'] ?></span>
  <?= $badge ?>
</a>
<?php endforeach; ?>

<?php if ($is_admin_eff): ?>
<div class="sidebar-section-title">Administration</div>
<?php foreach ($admin_links as $key => $link): ?>
<?php $active_cls = ($current_page === $key) ? ' active' : ''; ?>
<a href="<?= $link['href'] ?>" class="sidebar-item<?= $active_cls ?>">
  <span class="sidebar-item-icon" aria-hidden="true"><?= $link['icon'] ?></span>
  <span class="sidebar-item-label"><?= $link['label'] ?></span>
</a>
<?php endforeach; ?>
<?php foreach ($extra_admin_links as $key => $link): ?>
<?php $active_cls = ($current_page === $key) ? ' active' : ''; ?>
<a href="<?= $link['href'] ?>" class="sidebar-item<?= $active_cls ?>">
  <span class="sidebar-item-icon" aria-hidden="true"><?= $link['icon'] ?></span>
  <span class="sidebar-item-label"><?= $link['label'] ?></span>
</a>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($owned_forms !== []): ?>
<div class="sidebar-section-title">Mes formulaires</div>
<?php $active_cls = ($current_page === 'my_forms') ? ' active' : ''; ?>
<a href="index.php?p=my_forms" class="sidebar-item<?= $active_cls ?>">
  <span class="sidebar-item-icon" aria-hidden="true">📊</span>
  <span class="sidebar-item-label">Suivi de mes formulaires</span>
</a>
<?php endif; ?>
