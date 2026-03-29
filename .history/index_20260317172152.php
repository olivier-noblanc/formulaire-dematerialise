<?php
// admin/index.php — Redirige vers la page d'accès admin
require_once __DIR__ . '/helpers.php';

if (!is_admin_user() && !is_super_admin()) {
    header('Location: admin_access.php');
    exit;
}

// Sinon afficher le menu du back office
// ou rediriger vers la première page réelle
header('Location: dashboard.php');
exit;
