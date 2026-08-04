<?php
require_once __DIR__ . '/regression/Bug09_TopbarLinkTest.php';
require_once __DIR__ . '/regression/Bug11_NoTopbarBreadcrumbTest.php';
$ok = run_bug09_test() && run_bug11_test();
exit($ok ? 0 : 1);
