<?php
// Détection automatique du hostname
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL',       $protocol . '://' . $hostname . '/workflow');
define('DB_PATH',        __DIR__ . '/db/workflow.db');
define('SMTP_HOST',      'smtp.social.gouv.fr');
define('SMTP_PORT',      25);
define('SMTP_FROM',      'workflow@dreets.gouv.fr');
define('SMTP_FROM_NAME', 'Workflow DREETS');
define('DELAI_RELANCE_H', 48);
// Email de l'administrateur principal
define('ADMIN_EMAIL',    'olivier.noblanc@dreets.gouv.fr');
date_default_timezone_set('Europe/Paris');
