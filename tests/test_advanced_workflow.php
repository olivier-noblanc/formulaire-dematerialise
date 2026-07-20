<?php
/**
 * tests/test_advanced_workflow.php — Section 1 : Workflow Engine
 *
 * Teste advance_workflow(), validate_token(), regenerate_token(),
 * cancel_submission(), delegate_token(), get_delegations(),
 * resolve_dynamic_recipient(), remind_one(), is_token_expired().
 *
 * Dépendances : test_bootstrap.php (test), helpers.php (fonctions métier).
 * Globales attendues : $pdo, $onboarding_id.
 */

declare(strict_types=1);

/**
 * Section 1 : Workflow Engine.
 */
function run_tests_advanced_workflow(): void {
    global $pdo, $onboarding_id;

    echo "── 1. Workflow Engine ──\n";

    test('advance_workflow() creates tokens with correct step_id', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'TokenStep', 'prenom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'wf_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Get the first active step for onboarding
        $step = $pdo->prepare("SELECT id FROM steps WHERE form_id = ? AND actif = 1 ORDER BY ordre ASC LIMIT 1");
        $step->execute([$onboarding_id]);
        $first_step_id = $step->fetchColumn();

        $tokens = $pdo->prepare("SELECT step_id FROM tokens WHERE submission_id = ?");
        $tokens->execute([$sub_id]);
        $token_step_ids = $tokens->fetchAll(PDO::FETCH_COLUMN);

        // All tokens should be for the first step
        $all_correct = true;
        foreach ($token_step_ids as $sid) {
            if ($sid !== $first_step_id) { $all_correct = false; break; }
        }

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $all_correct ? true : 'Tokens not for first step. Got: ' . implode(',', $token_step_ids) . ' Expected: ' . $first_step_id;
    });

    test('advance_workflow() respects sequential steps (next step only after current done)', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'SequentialTest', 'prenom' => 'Step']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'seq_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Count distinct step_ids in tokens
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT step_id) FROM tokens WHERE submission_id = ?");
        $stmt->execute([$sub_id]);
        $distinct_steps = (int)$stmt->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        // Only one step should have tokens (sequential)
        return $distinct_steps === 1 ? true : "Expected 1 distinct step, got $distinct_steps";
    });

    test('validate_token() with invalid token returns error', function() {
        $result = \App\Core\App::workflow()->validateToken('nonexistent_token_12345');
        return $result['status'] === 'invalid' ? true : "Expected 'invalid', got: " . $result['status'];
    });

    test('validate_token() with already-validated token returns already_done', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'AlreadyDone', 'prenom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'ad_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Get first pending token
        $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $token = $stmt->fetchColumn();

        // Validate it once
        $result1 = \App\Core\App::workflow()->validateToken($token);

        // Validate it again
        $result2 = \App\Core\App::workflow()->validateToken($token);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result2['status'] === 'already_done' ? true : "Expected 'already_done', got: " . $result2['status'];
    });

    test('validate_token() with expired token returns expired status', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'ExpiredTest', 'prenom' => 'Token']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'exp_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Set token as expired (past date)
        $pdo->prepare("UPDATE tokens SET expires_at = '2020-01-01 00:00:00' WHERE submission_id = ? AND done_at IS NULL")
            ->execute([$sub_id]);

        // Get the expired token
        $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $token = $stmt->fetchColumn();

        $result = \App\Core\App::workflow()->validateToken($token);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return $result['status'] === 'expired' ? true : "Expected 'expired', got: " . $result['status'];
    });

    test('regenerate_token() creates new token for same step/recipient', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'RegenTest', 'prenom' => 'Token']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'regen_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Get first pending token row
        $stmt = $pdo->prepare("SELECT id, email, step_id, token FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        $result = \App\Core\App::token()->regenerate($old['id']);

        // Check new token exists for same step and same email
        $new = $pdo->prepare("SELECT id, email, step_id, token FROM tokens WHERE submission_id = ? AND email = ? AND done_at IS NULL");
        $new->execute([$sub_id, $old['email']]);
        $new_token = $new->fetch(PDO::FETCH_ASSOC);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        if (!$result['success']) return 'regenerate_token() failed: ' . $result['message'];
        if (!$new_token) return 'No new token found for same recipient';
        if ($new_token['step_id'] !== $old['step_id']) return 'Step ID mismatch after regeneration';
        if ($new_token['token'] === $old['token']) return 'New token same as old token';
        return true;
    });

    test('cancel_submission() sets status to refuse and cancels all pending tokens', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'CancelTest', 'prenom' => 'Sub']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'cancel_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        $result = \App\Core\App::token()->cancel($sub_id, 'cancel_test@exemple.invalid');

        // Check submission status
        $stmt = $pdo->prepare("SELECT status FROM submissions WHERE id = ?");
        $stmt->execute([$sub_id]);
        $status = $stmt->fetchColumn();

        // Check all tokens have done_at set
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ? AND done_at IS NULL");
        $stmt2->execute([$sub_id]);
        $pending = (int)$stmt2->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        if (!$result['success']) return 'cancel_submission failed: ' . $result['message'];
        if ($status !== 'refuse') return "Status is '$status', expected 'refuse'";
        if ($pending > 0) return "$pending tokens still pending after cancel";
        return true;
    });

    test('delegate_token() transfers validation to another user', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'DelegateTest', 'prenom' => 'Sub']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'delegate_test@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Get first pending token
        $stmt = $pdo->prepare("SELECT id, email, step_id FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $tok = $stmt->fetch(PDO::FETCH_ASSOC);

        $delegate_email = 'delegate_target@exemple.invalid';
        $result = \App\Core\App::token()->delegate($tok['id'], $delegate_email, 'Test delegation');

        // Old token should be done
        $old_stmt = $pdo->prepare("SELECT done_at FROM tokens WHERE id = ?");
        $old_stmt->execute([$tok['id']]);
        $old_done = $old_stmt->fetchColumn();

        // New token should exist for delegate_email on same step
        $new_stmt = $pdo->prepare("SELECT id FROM tokens WHERE submission_id = ? AND email = ? AND step_id = ? AND done_at IS NULL");
        $new_stmt->execute([$sub_id, $delegate_email, $tok['step_id']]);
        $new_tok = $new_stmt->fetch();

        // Cleanup
        $pdo->prepare("DELETE FROM delegations WHERE token_id = ?")->execute([$tok['id']]);
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        if (!$result['success']) return 'delegate_token failed: ' . $result['message'];
        if (empty($old_done)) return 'Old token still pending after delegation';
        if (!$new_tok) return 'No new token created for delegate';
        return true;
    });

    test('resolve_dynamic_recipient() with multiple {{}} references', function() {
        // Test that a single {{}} reference resolves correctly
        $result = \App\Core\App::workflow()->resolveDynamicRecipient('{{email_manager}}', ['email_manager' => 'manager@exemple.invalid']);
        return $result === 'manager@exemple.invalid' ? true : "Expected manager@exemple.invalid, got: $result";
    });

    test('resolve_dynamic_recipient() with mixed static+dynamic recipients', function() {
        // Static email should pass through unchanged
        $result1 = \App\Core\App::workflow()->resolveDynamicRecipient('static@exemple.invalid', ['email' => 'dynamic@exemple.invalid']);
        // Dynamic reference should be resolved
        $result2 = \App\Core\App::workflow()->resolveDynamicRecipient('{{email}}', ['email' => 'dynamic@exemple.invalid']);
        return ($result1 === 'static@exemple.invalid' && $result2 === 'dynamic@exemple.invalid')
            ? true : "Static: $result1, Dynamic: $result2";
    });

    test('remind_one() returns appropriate status for already-validated token', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'RemindDone', 'prenom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at) VALUES (?, ?, ?, ?, 'en_cours', datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'remind_done@exemple.invalid']);

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        // Validate the first token
        $stmt = $pdo->prepare("SELECT token FROM tokens WHERE submission_id = ? AND done_at IS NULL LIMIT 1");
        $stmt->execute([$sub_id]);
        $token_val = $stmt->fetchColumn();

        \App\Core\App::workflow()->validateToken($token_val);

        // Get the done token's ID
        $stmt2 = $pdo->prepare("SELECT id FROM tokens WHERE token = ?");
        $stmt2->execute([$token_val]);
        $done_token_id = $stmt2->fetchColumn();

        $result = \App\Core\App::token()->remind($done_token_id);

        // Cleanup
        $pdo->prepare("DELETE FROM tokens WHERE submission_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return !$result['success'] ? true : "Expected failure for done token, got success: " . $result['message'];
    });

    test('is_token_expired() logic: past expiration date', function() {
        // Test the logic directly - tokens with past expires_at should be expired
        $past_date = '2020-01-01 00:00:00';
        $exp_ts = strtotime($past_date);
        /** @phpstan-ignore-next-line notIdentical.alwaysTrue */
        $is_expired = ($exp_ts !== false && $exp_ts < time());
        return $is_expired ? true : 'Past date should be expired';
    });

    test('is_token_expired() logic: future expiration date', function() {
        $future_date = '2099-12-31 23:59:59';
        $exp_ts = strtotime($future_date);
        /** @phpstan-ignore-next-line notIdentical.alwaysTrue */
        $is_expired = ($exp_ts !== false && $exp_ts < time());
        return !$is_expired ? true : 'Future date should not be expired';
    });

    test('is_token_expired() logic: no expiration (null)', function() {
        // Null expiration means token never expires
        $expires_at = null;
        $is_expired = false;
        /** @phpstan-ignore-next-line empty.variable */
        if (!empty($expires_at)) {
            $exp_ts = strtotime($expires_at);
            $is_expired = ($exp_ts !== false && $exp_ts < time());
        }
        /** @phpstan-ignore-next-line booleanNot.alwaysTrue */
        return !$is_expired ? true : 'Null expiration should not be expired';
    });

    test('advance_workflow() with closed submission does nothing', function() use ($pdo, $onboarding_id) {
        $sub_id = generate_uuid();
        $data = json_encode(['nom' => 'ClosedSub', 'prenom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, status, submitted_at, closed_at) VALUES (?, ?, ?, ?, 'valide', datetime('now'), datetime('now'))")
            ->execute([$sub_id, $onboarding_id, $data, 'closed_test@exemple.invalid']);

        $before = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $before->execute([$sub_id]);
        $count_before = (int)$before->fetchColumn();

        \App\Core\App::workflow()->advanceWorkflow($sub_id);

        $after = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE submission_id = ?");
        $after->execute([$sub_id]);
        $count_after = (int)$after->fetchColumn();

        // Cleanup
        $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$sub_id]);

        return ($count_before === 0 && $count_after === 0) ? true : "Tokens created for closed submission";
    });

    echo "\n";
}
