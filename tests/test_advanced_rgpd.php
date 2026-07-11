<?php
/**
 * tests/test_advanced_rgpd.php — Section 3 : RGPD & Data Protection
 *
 * Tests RgpdService export/delete/purge, rate_limit_check(), get_allowed_extensions().
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

    test('RgpdService::exportUserData() returns structured array with user data', function() use ($pdo, $onboarding_id) {
        $test_email = 'rgpd_export@dreets.gouv.fr';
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'RgpdExport', 'prenom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, $test_email]);

        $export = \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData($test_email);

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

    test('RgpdService::exportUserData() for user with no data returns empty sections', function() {
        $export = \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData('nonexistent_user_99999@nodata.test');
        return (empty($export['submissions']) && empty($export['validations']))
            ? true : 'Expected empty sections for non-existent user';
    });

    test('RgpdService::deleteUserData() anonymizes submission data', function() use ($pdo, $onboarding_id) {
        $test_email = 'rgpd_delete@dreets.gouv.fr';
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'ToDelete', 'prenom' => 'User', 'email' => $test_email]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, $test_email]);

        $result = \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->deleteUserData($test_email);

        // Check that submitted_by is anonymized
        $stmt = $pdo->prepare("SELECT submitted_by, data FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        if (!$result) return 'deleteUserData returned false';
        if ($row['submitted_by'] !== '[supprimé]') return 'submitted_by not anonymized: ' . $row['submitted_by'];

        $decoded = json_decode($row['data'], true);
        if ($decoded['nom'] !== '[supprimé]') return 'nom not anonymized';
        if ($decoded['prenom'] !== '[supprimé]') return 'prenom not anonymized';
        return true;
    });

    test('RgpdService::autoPurge() respects retention period', function() use ($pdo, $onboarding_id) {
        // Create an old closed submission (more than 24 months ago)
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'OldPurge', 'prenom' => 'Test']);
        $old_date = date('Y-m-d H:i:s', strtotime('-25 months'));
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, ?, 'valide', ?, ?)")
            ->execute([$sub_id, $onboarding_id, $data, 'purge_test@dreets.gouv.fr', $old_date, $old_date]);

        $count = \App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge(24);

        // Check the old submission was deleted
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $remaining = (int)$stmt->fetchColumn();

        return $remaining === 0 ? true : "Old submission not purged. Purged count: $count";
    });

    test('AttachmentService::getAllowedExtensions() includes common safe formats', function() {
        $exts = \App\Core\App::attachment()->getAllowedExtensions();
        $required = ['pdf', 'docx', 'xlsx', 'jpg', 'png'];
        foreach ($required as $ext) {
            if (!in_array($ext, $exts)) return "Missing extension: $ext";
        }
        return true;
    });

    echo "\n";
}
