<?php
$_SERVER['HTTP_X_TEST_MODE'] = '1';
$_SERVER['HTTP_X_TEST_USER'] = 'admin@exemple.invalid';
require_once dirname(__DIR__) . '/helpers.php';

$db = \App\Core\App::getInstance()->get(\App\Core\Database::class);
$pdo = $db->getPdo();

$admin = $pdo->query("SELECT COUNT(*) FROM admins WHERE email = 'admin@exemple.invalid'")->fetchColumn();
echo "admin@exemple.invalid in admins: " . ($admin > 0 ? 'YES' : 'NO') . "\n";

$auth = \App\Core\App::getInstance()->get(\App\Auth\AuthService::class);
echo "isAdmin: " . var_export($auth->isAdmin(), true) . "\n";
echo "isSuperAdmin: " . var_export($auth->isSuperAdmin(), true) . "\n";
