<?php
/**
 * tests/test_advanced_rgpd.php — Section 3 : RGPD & Data Protection
 *
 * Teste rgpd_export_user_data(), rgpd_delete_user_data(), rgpd_auto_purge(),
 * rate_limit_check() (limite, indépendance par action/IP), get_allowed_extensions().
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id.
 */

declare(strict_types=1);

/**
 * Section 3 : RGPD & Data Protection.
 */
function run_tests_advanced_rgpd(): void {
    global $pdo, $onboarding_id;

    echo "── 3. RGPD & Data Protection ──\n";

    test('rgpd_export_user_data() returns structured array with user data', function() use ($pdo, $onboarding_id) {
        $test_email = 'rgpd_export@exemple.invalid';
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'RgpdExport', 'prenom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, $test_email]);

        $export = rgpd_export_user_data($test_email);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        if (!isset($export['email'])) return 'Missing email key';
        if (!isset($export['submissions'])) return 'Missing submissions key';
        if (!isset($export['validations'])) return 'Missing validations key';
        if ($export['email'] !== $test_email) return 'Email mismatch';
        if (count($export['submissions']) === 0) return 'No submissions found for test user';
        return true;
    });

    test('rgpd_export_user_data() for user with no data returns empty sections', function() {
        $export = rgpd_export_user_data('nonexistent_user_99999@nodata.test');
        return (empty($export['submissions']) && empty($export['validations']))
            ? true : 'Expected empty sections for non-existent user';
    });

    test('rgpd_delete_user_data() anonymizes submission data', function() use ($pdo, $onboarding_id) {
        $test_email = 'rgpd_delete@exemple.invalid';
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'ToDelete', 'prenom' => 'User', 'email' => $test_email]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, $test_email]);

        $result = rgpd_delete_user_data($test_email);

        // Check that submitted_by is anonymized
        $stmt = $pdo->prepare("SELECT submitted_by, data FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        if (!$result) return 'rgpd_delete_user_data returned false';
        if ($row['submitted_by'] !== '[supprimé]') return 'submitted_by not anonymized: ' . $row['submitted_by'];

        $decoded = json_decode($row['data'], true);
        if ($decoded['nom'] !== '[supprimé]') return 'nom not anonymized';
        if ($decoded['prenom'] !== '[supprimé]') return 'prenom not anonymized';
        return true;
    });

    test('rgpd_auto_purge() respects retention period', function() use ($pdo, $onboarding_id) {
        // Create an old closed submission (more than 24 months ago)
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'OldPurge', 'prenom' => 'Test']);
        $old_date = date('Y-m-d H:i:s', strtotime('-25 months'));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, ?, 'valide', ?, ?)")
            ->execute([$sub_id, $onboarding_id, $data, 'purge_test@exemple.invalid', $old_date, $old_date]);

        $count = rgpd_auto_purge(24);

        // Check the old submission was deleted
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $remaining = (int)$stmt->fetchColumn();

        return $remaining === 0 ? true : "Old submission not purged. Purged count: $count";
    });

    test('rate_limit_check() returns true when under limit', function() {
        // Use a unique action to avoid interference
        $action = 'test_under_limit_' . bin2hex(random_bytes(4));
        $result = rate_limit_check($action, 10, 60);
        return $result ? true : 'Rate limit should allow first request';
    });

    test('rate_limit_check() returns false when over limit', function() {
        $pdo = get_pdo();
        $action = 'test_over_limit_' . bin2hex(random_bytes(4));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $max = 3;

        // Insert records with SQLite's datetime('now') to match the UTC timestamps
        // used by rate_limit_check() (which uses SQLite's own time functions everywhere).
        for ($i = 0; $i < $max; $i++) {
            $pdo->prepare("INSERT INTO rate_limits (id, action_key, ip, attempted_at) VALUES (?, ?, ?, datetime('now'))")
                ->execute([generate_uuid(), $action, $ip]);
        }

        // Now call rate_limit_check — it should see $max records and block
        $result = rate_limit_check($action, $max, 60);
        return !$result ? true : 'Rate limit should block after exceeding (max=' . $max . ')';
    });

    test('rate_limit_check() different actions are independent', function() {
        $pdo = get_pdo();
        $action1 = 'test_indep1_' . bin2hex(random_bytes(4));
        $action2 = 'test_indep2_' . bin2hex(random_bytes(4));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $max = 3;

        // Exhaust action1 by inserting records with SQLite UTC time
        for ($i = 0; $i < $max; $i++) {
            $pdo->prepare("INSERT INTO rate_limits (id, action_key, ip, attempted_at) VALUES (?, ?, ?, datetime('now'))")
                ->execute([generate_uuid(), $action1, $ip]);
        }
        $blocked = rate_limit_check($action1, $max, 60);

        // Action2 should still work
        $allowed = rate_limit_check($action2, $max, 60);

        return ($blocked === false && $allowed === true) ? true : 'Actions should be independent (blocked=' . ($blocked ? 'true' : 'false') . ', allowed=' . ($allowed ? 'true' : 'false') . ')';
    });

    test('rate_limit_check() different IPs are independent', function() {
        $action = 'test_ip_indep_' . bin2hex(random_bytes(4));
        $original_ip = $_SERVER['REMOTE_ADDR'] ?? null;

        // Use first IP and exhaust: 5 calls fill the limit, 6th should be blocked
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        for ($i = 0; $i < 5; $i++) {
            rate_limit_check($action, 5, 60);
        }
        $blocked_ip1 = rate_limit_check($action, 5, 60);

        // Different IP should still be allowed
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $allowed_ip2 = rate_limit_check($action, 5, 60);

        // Restore
        if ($original_ip !== null) {
            $_SERVER['REMOTE_ADDR'] = $original_ip;
        } else {
            unset($_SERVER['REMOTE_ADDR']);
        }

        return ($blocked_ip1 === false && $allowed_ip2 === true) ? true : 'Different IPs should be independent. IP1 blocked: ' . ($blocked_ip1 ? 'yes' : 'no') . ', IP2 allowed: ' . ($allowed_ip2 ? 'yes' : 'no');
    });

    test('get_allowed_extensions() includes common safe formats', function() {
        $exts = get_allowed_extensions();
        $required = ['pdf', 'docx', 'xlsx', 'jpg', 'png'];
        foreach ($required as $ext) {
            if (!in_array($ext, $exts)) return "Missing extension: $ext";
        }
        return true;
    });

    echo "\n";
}
