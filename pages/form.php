<?php
declare(strict_types=1);

// form.php?f=<slug> — affiche et traite le formulaire d'un slug donné
// Thin wrapper : instancie le contrôleur et appelle handle().
// Toute la logique métier vit dans src/Controller/FormController.php.
require_once dirname(__DIR__) . '/helpers.php';

$controller = new \App\Controller\FormController();
$controller->handle();
