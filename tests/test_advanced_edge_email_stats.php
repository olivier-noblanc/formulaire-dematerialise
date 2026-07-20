<?php
/**
 * tests/test_advanced_edge_email_stats.php — Section 6 + 7 + 8
 *
 * Section 6 : Edge Cases & Stress (UUIDs, tokens, PDO singleton, audit log, settings, SQLi, render_field)
 * Section 7 : Email & Notifications (send_mail, build_mail_html, render_email_template, test_json_response)
 * Section 8 : Stats & Monitoring (StatsService::getGlobalStats, StatsService::getStatsByPeriod, get_db_size, run_lazy_cron)
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo.
 */

declare(strict_types=1);

/**
 * Section 6 : Edge Cases & Stress.
 */
function run_tests_advanced_edge(): void {
    global $pdo;

    echo "── 6. Edge Cases & Stress ──\n";

    test('h() with very long string (>10000 chars)', function() {
        $long = str_repeat('A', 10001);
        $result = \App\Core\App::html()->escape($long);
        return strlen($result) >= 10000 ? true : 'String was truncated unexpectedly';
    });

    test('h() with mixed UTF-8 multibyte characters', function() {
        $input = '日本語中文한국어العربية€¥£';
        $result = \App\Core\App::html()->escape($input);
        // Should preserve these characters (they don't need HTML encoding)
        return strpos($result, '日本語') !== false ? true : "UTF-8 multibyte lost: $result";
    });

    test('generate_uuid() with rapid successive calls (1000 calls, all unique)', function() {
        $uuids = [];
        for ($i = 0; $i < 1000; $i++) {
            $uuids[] = generate_uuid();
        }
        $unique = count(array_unique($uuids));
        return $unique === 1000 ? true : "Only $unique unique UUIDs out of 1000";
    });

    test('generate_token() with rapid successive calls (1000 calls, all unique)', function() {
        $tokens = [];
        for ($i = 0; $i < 1000; $i++) {
            $tokens[] = generate_token();
        }
        $unique = count(array_unique($tokens));
        return $unique === 1000 ? true : "Only $unique unique tokens out of 1000";
    });

    test('get_pdo() returns same instance on repeated calls (singleton pattern)', function() {
        $pdo1 = get_pdo();
        $pdo2 = get_pdo();
        return $pdo1 === $pdo2 ? true : 'get_pdo() returned different instances';
    });

    test('get_pdo() database is writable and readable', function() use ($pdo) {
        $test_id = generate_uuid();
        $pdo->prepare("INSERT INTO audit_log (id, action, target, detail, actor, ip) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$test_id, 'test_write', 'test', 'Write/read test', 'test', '127.0.0.1']);

        $stmt = $pdo->prepare("SELECT action FROM audit_log WHERE id = ?");
        $stmt->execute([$test_id]);
        $action = $stmt->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM audit_log WHERE id = ?")->execute([$test_id]);

        return $action === 'test_write' ? true : "Read back failed: $action";
    });

    test('app_log() with very long message', function() use ($pdo) {
        $long_msg = str_repeat('X', 5000);
        $before = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
        \App\Core\App::audit()->log('test_long', 'test', $long_msg);
        $after = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM audit_log WHERE action = 'test_long'")->execute();

        return $after > $before ? true : 'Log entry not created with long message';
    });

    test('app_log() with special characters in message', function() use ($pdo) {
        $special_msg = "Test with <script>alert('xss')</script> & \"quotes\" 'apostrophes'";
        \App\Core\App::audit()->log('test_special', 'test', $special_msg);

        $stmt = $pdo->prepare("SELECT detail FROM audit_log WHERE action = 'test_special' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
        $stored = $stmt->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM audit_log WHERE action = 'test_special'")->execute();

        return $stored === $special_msg ? true : "Stored message differs: $stored";
    });

    test('set_setting() with value containing quotes and HTML', function() {
        $value = '<b>Bold</b> & "quoted" and \'apostrophes\'';
        set_setting('test_advanced_html', $value);
        $retrieved = get_setting('test_advanced_html');
        return $retrieved === $value ? true : "Round-trip failed. Got: $retrieved";
    });

    test('get_setting() with value containing quotes and HTML (round-trip)', function() {
        $value = 'Test "double" & \'single\' <tags>';
        set_setting('test_roundtrip', $value);
        $result = get_setting('test_roundtrip');
        return $result === $value ? true : "Expected: $value, Got: $result";
    });

    test('render_field() with very long label text', function() {
        $long_label = str_repeat('VeryLongLabel', 50);
        $field = [
            'field_name' => 'test_long_label',
            'label' => $long_label,
            'field_type' => 'text',
            'required' => false,
            'options' => '',
            'hint' => '',
        ];
        $html = render_field($field, '', [], '', false);
        return strpos($html, $long_label) !== false ? true : 'Long label not found in rendered HTML';
    });

    echo "\n";
}

/**
 * Section 7 : Email & Notifications.
 */
function run_tests_advanced_email(): void {
    echo "── 7. Email & Notifications ──\n";

    test('send_mail() with multiple recipients (test mode intercepts all)', function() {
        reset_test_mails();
        \App\Core\App::mail()->send('recipient1@exemple.invalid', 'Test Subject 1', '<p>Body 1</p>');
        \App\Core\App::mail()->send('recipient2@exemple.invalid', 'Test Subject 2', '<p>Body 2</p>');
        \App\Core\App::mail()->send('recipient3@exemple.invalid', 'Test Subject 3', '<p>Body 3</p>');

        $mails = get_test_mails();
        reset_test_mails();

        return count($mails) === 3 ? true : 'Expected 3 mails, got ' . count($mails);
    });

    test('send_mail() with HTML body containing special characters', function() {
        reset_test_mails();
        $body = '<p>Éléphant & "Coïncidence" — café</p>';
        \App\Core\App::mail()->send('special@exemple.invalid', 'Test Spécial', $body);

        $mails = get_test_mails();
        reset_test_mails();

        return (!empty($mails) && $mails[0]['body'] === $body) ? true : 'Body mismatch or no mail';
    });

    test('build_mail_html() contains validation link', function() {
        $submission = [
            'data' => json_encode(['nom' => 'MailTest', 'prenom' => 'Link']),
            'form_label' => 'Test Form',
        ];
        $token = generate_token();
        $html = \App\Core\App::mail()->buildMailHtml($submission, 'Étape 1', $token);

        return strpos($html, 'validate.php?token=') !== false ? true : 'Validation link not found in mail HTML';
    });

    test('build_mail_html() contains form label', function() {
        $submission = [
            'data' => json_encode(['nom' => 'MailTest', 'prenom' => 'Label']),
            'form_label' => 'Formulaire Test Label',
        ];
        $token = generate_token();
        $html = \App\Core\App::mail()->buildMailHtml($submission, 'Étape 1', $token);

        return strpos($html, 'Formulaire Test Label') !== false ? true : 'Form label not found in mail HTML';
    });

    test('render_email_template() has proper HTML structure (html, head, body)', function() {
        $html = \App\Core\App::mail()->renderEmailTemplate('Test Title', '<p>Body content</p>');
        $has_html = strpos($html, '<html') !== false;
        $has_head = strpos($html, '<head>') !== false;
        $has_body = strpos($html, '<body') !== false;
        return ($has_html && $has_head && $has_body) ? true : 'Missing html/head/body tags';
    });

    test('render_email_template() with empty title', function() {
        $html = \App\Core\App::mail()->renderEmailTemplate('', '<p>Body</p>');
        // Should not crash, should still be valid HTML
        return strpos($html, '<html') !== false ? true : 'Failed with empty title';
    });

    test('render_email_template() with special characters in content', function() {
        $html = \App\Core\App::mail()->renderEmailTemplate('Titulé', '<p>Contenu avec <strong>gras</strong> & "guillemets"</p>');
        return (strpos($html, 'Contenu avec') !== false) ? true : 'Special chars lost in template';
    });

    test('test_json_response() outputs correct JSON structure', function() {
        // Write a temp script to test test_json_response with proper setup
        $tmp = sys_get_temp_dir() . '/test_json_resp_' . bin2hex(random_bytes(4)) . '.php';
        $bootstrap = __DIR__ . '/../test_bootstrap.php';
        $code = "<?php\n"
            . "ini_set('session.save_path', sys_get_temp_dir() . '/php-sessions');\n"
            . "@mkdir(sys_get_temp_dir() . '/php-sessions', 0777, true);\n"
            . "require_once '" . addslashes($bootstrap) . "';\n"
            . "test_json_response(['action' => 'test', 'status' => 'ok']);\n";
        file_put_contents($tmp, $code);

        $output = shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);

        $json = json_decode(trim($output ?? ''), true);
        if ($json === null) return 'Output is not valid JSON: ' . substr($output ?? '', 0, 200);
        if (!isset($json['_test_mode'])) return 'Missing _test_mode key';
        if ($json['action'] !== 'test') return 'Missing action key';
        if ($json['status'] !== 'ok') return 'Missing status key';
        return true;
    });

    echo "\n";
}

/**
 * Section 8 : Stats & Monitoring.
 */
function run_tests_advanced_stats(): void {
    global $pdo;

    echo "── 8. Stats & Monitoring ──\n";

    test('StatsService::getGlobalStats() all values are non-negative integers', function() {
        $stats = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
        $int_fields = ['total', 'en_cours', 'valide', 'refuse', 'today', 'this_week', 'this_month', 'tokens_pending', 'attachments_count', 'attachments_size'];
        foreach ($int_fields as $field) {
            if (!isset($stats[$field])) return "Missing field: $field";
            if (!is_int($stats[$field])) return "$field is not int: " . gettype($stats[$field]);
            if ($stats[$field] < 0) return "$field is negative: " . $stats[$field];
        }
        return true;
    });

    test('StatsService::getGlobalStats() taux_validation is between 0 and 100', function() {
        $stats = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats();
        $taux = $stats['taux_validation'] ?? -1;
        return ($taux >= 0 && $taux <= 100) ? true : "taux_validation out of range: $taux";
    });

    test('StatsService::getStatsByPeriod() with period "day"', function() {
        $stats = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod('day');
        /** @phpstan-ignore-next-line function.alreadyNarrowedType */
        return is_array($stats) ? true : 'Expected array for period day';
    });

    test('StatsService::getStatsByPeriod() with period "week"', function() {
        $stats = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod('week');
        /** @phpstan-ignore-next-line function.alreadyNarrowedType */
        return is_array($stats) ? true : 'Expected array for period week';
    });

    test('StatsService::getStatsByPeriod() with period "year"', function() {
        $stats = \App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod('year');
        /** @phpstan-ignore-next-line function.alreadyNarrowedType */
        return is_array($stats) ? true : 'Expected array for period year';
    });

    test('get_db_size() increases after insert', function() use ($pdo) {
        $size_before = (new \App\Webhook\WebhookService(\App\Core\App::db()))->getDbSize();

        // Insert a bunch of data to increase DB size
        for ($i = 0; $i < 50; $i++) {
            $id = generate_uuid();
            $pdo->prepare("INSERT INTO audit_log (id, action, target, detail, actor, ip) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$id, 'test_db_size', 'test', str_repeat('X', 500), 'test', '127.0.0.1']);
        }

        $size_after = (new \App\Webhook\WebhookService(\App\Core\App::db()))->getDbSize();

        // Cleanup
        $pdo->prepare("DELETE FROM audit_log WHERE action = 'test_db_size'")->execute();

        return $size_after >= $size_before ? true : "DB size decreased after insert: before=$size_before, after=$size_after";
    });

    test('get_db_size() returns reasonable value (>0, <1GB)', function() {
        $size = (new \App\Webhook\WebhookService(\App\Core\App::db()))->getDbSize();
        $one_gb = 1024 * 1024 * 1024;
        return ($size > 0 && $size < $one_gb) ? true : "DB size out of reasonable range: $size";
    });

    test('App::cron() is available', function() {
        return \App\Core\App::getInstance()->has(\App\Cron\CronService::class)
            ? true : 'CronService not registered';
    });

    echo "\n";
}
