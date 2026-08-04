<?php
/**
 * Template: full HTML page shell.
 * Variables: $full_title, $body_attr, $nav_html, $before_main,
 *            $container_class, $content, $after_main, $footer_html, $script_nonce
 * @var string $full_title
 * @var string $body_attr
 * @var string $nav_html
 * @var string $before_main
 * @var string $container_class
 * @var string $content
 * @var string $after_main
 * @var string $footer_html
 * @var string $script_nonce
 */
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $full_title ?></title>
  <?= \App\Render\NavigationRenderer::favicon() ?>
  <link rel="stylesheet" href="assets.php?type=css">
</head>
<body<?= $body_attr ? ' ' . $body_attr : '' ?>>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= $nav_html ?>
<?= $before_main ?>
<main class="<?= \App\Core\App::html()->escape($container_class) ?>" id="main-content">
<?= $content ?>
</main>
<?= $after_main ?>
<?= $footer_html ?>
<script src="assets.php?type=js&file=app" nonce="<?= $script_nonce ?>"></script>
</body>
</html>
