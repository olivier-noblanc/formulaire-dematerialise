<?php
// pages/accueil.php — Page d'accueil adaptée au rôle de l'utilisateur
// Thin wrapper : instancie le contrôleur et appelle handle().
// Toute la logique métier vit dans src/Controller/IndexController.php.

$controller = new \App\Controller\IndexController();
$controller->handle();
