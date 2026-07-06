<?php
declare(strict_types=1);

// dashboard.php — Tableau de bord administrateur (liste des demandes en cours)
// Thin wrapper : instancie le contrôleur et appelle handle().
// Toute la logique métier vit dans src/Controller/DashboardController.php.
require_once dirname(__DIR__) . '/helpers.php';

$controller = new \App\Controller\DashboardController();
$controller->handle();
