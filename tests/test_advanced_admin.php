<?php
/**
 * tests/test_advanced_admin.php — Section 4 : Admin Management
 *
 * Teste process_admin_request(), approve_admin_request(), reject_admin_request(),
 * remove_admin(), is_admin_user(), get_admin_email(), is_super_admin(), require_admin().
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo.
 */

declare(strict_types=1);

/**
 * Section 4 : Admin Management.
 */
function run_tests_advanced_admin(): void {
    global $pdo;

    echo "── 4. Admin Management ──\n";

    test('process_admin_request() creates admin request', function() use ($pdo) {
        $test_email = 'admin_req_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        // Make sure the user is not already admin
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);

        // Temporarily set test user to a non-admin
        $old_user = $_SERVER['HTTP_X_TEST_USER'];
        $_SERVER['HTTP_X_TEST_USER'] = $test_email;

        $result = process_admin_request($test_email);

        // Verify request was created
        $stmt = $pdo->prepare("SELECT status FROM admin_requests WHERE email = ?");
        $stmt->execute([$test_email]);
        $status = $stmt->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);
        $_SERVER['HTTP_X_TEST_USER'] = $old_user;

        return ($result['success'] && $status === 'pending') ? true : "Expected success + pending, got: " . json_encode($result) . ", status=$status";
    });

    test('approve_admin_request() adds user to admins table', function() use ($pdo) {
        $test_email = 'admin_approve_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        // Cleanup
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);

        // Create request first
        $ar_id = generate_uuid();
        $pdo->prepare("INSERT INTO admin_requests (id, email, status, token, requested_at) VALUES (?, ?, 'pending', ?, datetime('now'))")
            ->execute([$ar_id, $test_email, generate_token()]);

        $result = approve_admin_request($test_email);

        // Check user is in admins table
        $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
        $stmt->execute([$test_email]);
        $is_admin = $stmt->fetch() !== false;

        // Also check request status
        $stmt2 = $pdo->prepare("SELECT status FROM admin_requests WHERE email = ?");
        $stmt2->execute([$test_email]);
        $status = $stmt2->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);

        return ($result && $is_admin && $status === 'approved') ? true : "result=$result, is_admin=" . ($is_admin ? 'yes' : 'no') . ", status=$status";
    });

    test('reject_admin_request() marks request as rejected', function() use ($pdo) {
        $test_email = 'admin_reject_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        // Cleanup
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);

        // Create request
        $ar_id = generate_uuid();
        $pdo->prepare("INSERT INTO admin_requests (id, email, status, token, requested_at) VALUES (?, ?, 'pending', ?, datetime('now'))")
            ->execute([$ar_id, $test_email, generate_token()]);

        $result = reject_admin_request($test_email);

        // Check request status
        $stmt = $pdo->prepare("SELECT status FROM admin_requests WHERE email = ?");
        $stmt->execute([$test_email]);
        $status = $stmt->fetchColumn();

        // Check user is NOT in admins table
        $stmt2 = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
        $stmt2->execute([$test_email]);
        $is_admin = $stmt2->fetch() !== false;

        // Cleanup
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);

        return ($result && $status === 'rejected' && !$is_admin) ? true : "result=$result, status=$status, is_admin=" . ($is_admin ? 'yes' : 'no');
    });

    test('remove_admin() removes user from admins table', function() use ($pdo) {
        $test_email = 'admin_remove_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        // Add user to admins
        $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([generate_uuid(), $test_email]);

        $result = remove_admin($test_email);

        // Check user is no longer admin
        $stmt = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
        $stmt->execute([$test_email]);
        $is_admin = $stmt->fetch() !== false;

        return ($result && !$is_admin) ? true : "result=$result, still_admin=" . ($is_admin ? 'yes' : 'no');
    });

    test('is_admin_user() after approval returns true', function() use ($pdo) {
        $test_email = 'admin_check_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);

        // Add to admins
        $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([generate_uuid(), $test_email]);

        $old_user = $_SERVER['HTTP_X_TEST_USER'];
        $_SERVER['HTTP_X_TEST_USER'] = $test_email;

        $result = is_admin_user();

        // Cleanup
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);
        $_SERVER['HTTP_X_TEST_USER'] = $old_user;

        return $result ? true : 'User should be admin after approval';
    });

    test('is_admin_user() after removal returns false', function() use ($pdo) {
        $test_email = 'admin_gone_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        // Add then remove
        $pdo->prepare("INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([generate_uuid(), $test_email]);
        remove_admin($test_email);

        $old_user = $_SERVER['HTTP_X_TEST_USER'];
        $_SERVER['HTTP_X_TEST_USER'] = $test_email;

        $result = is_admin_user();

        // Cleanup
        $_SERVER['HTTP_X_TEST_USER'] = $old_user;

        return !$result ? true : 'User should not be admin after removal';
    });

    test('admin request duplicate handling', function() use ($pdo) {
        $test_email = 'admin_dup_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
        $pdo->prepare("DELETE FROM admins WHERE email = ?")->execute([$test_email]);
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);

        $old_user = $_SERVER['HTTP_X_TEST_USER'];
        $_SERVER['HTTP_X_TEST_USER'] = $test_email;

        // First request should succeed
        $result1 = process_admin_request($test_email);

        // Second request (pending still exists) should return false
        $result2 = process_admin_request($test_email);

        // Cleanup
        $pdo->prepare("DELETE FROM admin_requests WHERE email = ?")->execute([$test_email]);
        $_SERVER['HTTP_X_TEST_USER'] = $old_user;

        return ($result1['success'] && !$result2['success']) ? true : "First: " . json_encode($result1) . ", Second: " . json_encode($result2) . " — expected success, fail";
    });

    test('get_admin_email() returns super admin email', function() {
        $email = get_admin_email();
        return (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ? true : "Invalid admin email: $email";
    });

    test('is_super_admin() only for main admin email', function() {
        $admin_email = get_admin_email();

        $old_user = $_SERVER['HTTP_X_TEST_USER'];

        // Test with admin email
        $_SERVER['HTTP_X_TEST_USER'] = $admin_email;
        $is_super = is_super_admin();

        // Test with different email
        $_SERVER['HTTP_X_TEST_USER'] = 'not_super_admin@dreets.gouv.fr';
        $not_super = is_super_admin();

        $_SERVER['HTTP_X_TEST_USER'] = $old_user;

        return ($is_super && !$not_super) ? true : 'super=' . ($is_super ? 'yes' : 'no') . ', not_super=' . ($not_super ? 'yes' : 'no');
    });

    test('require_admin() redirects non-admin in test mode', function() {
        $old_user = $_SERVER['HTTP_X_TEST_USER'];
        $_SERVER['HTTP_X_TEST_USER'] = 'notadmin@dreets.gouv.fr';

        // require_admin calls test_json_response which does exit, so test in subprocess
        $output = shell_exec('/home/z/my-project/bin/php/bin/php -r '
            . "'" . 'require_once ' . escapeshellarg('dirname(__DIR__) . '/test_bootstrap.php'') . ';'
            . '$_SERVER["HTTP_X_TEST_USER"] = "notadmin@dreets.gouv.fr";'
            . 'require_admin();'
            . "echo \"DID_NOT_EXIT\";'");

        $_SERVER['HTTP_X_TEST_USER'] = $old_user;

        // Should have exited (test_json_response calls exit), so DID_NOT_EXIT should NOT appear
        return (strpos($output ?? '', 'DID_NOT_EXIT') === false) ? true : 'require_admin did not exit for non-admin';
    });

    echo "\n";
}
