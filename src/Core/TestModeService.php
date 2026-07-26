<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Test mode utilities — email capture and JSON responses for E2E tests.
 *
 * B-EXIT (audit 2026-07-26) : ajout d'un mode 'no-exit' contrôlé par
 * $GLOBALS['_test_no_exit']. Quand ce flag est true, testJsonResponse()
 * NE fait PAS exit — elle stocke la réponse dans $GLOBALS['_test_json_output']
 * et retourne normalement. Permet de tester les controllers dans PHPUnit
 * sans crasher le process (exit() → "Premature end of PHPUnit process").
 *
 * Usage typique dans un test :
 *   $GLOBALS['_test_no_exit'] = true;
 *   (new FormController())->handle();
 *   $output = $GLOBALS['_test_json_output'];
 *   $this->assertSame('error', $output['error']);
 *   unset($GLOBALS['_test_no_exit']);
 */
final class TestModeService
{
    /**
     * Retrieve captured test emails.
     * @return array<int, array{to: string, subject: string, body: string, time: string}>
     */
    public static function getTestMails(): array
    {
        return $GLOBALS['_test_mails'] ?? [];
    }

    /**
     * Reset the test email queue.
     */
    public static function resetTestMails(): void
    {
        $GLOBALS['_test_mails'] = [];
    }

    /**
     * Output a JSON response and exit in test mode.
     *
     * In 'no-exit' mode ($GLOBALS['_test_no_exit'] = true), stores the data in
     * $GLOBALS['_test_json_output'] and returns instead of exiting. This allows
     * PHPUnit tests to exercise controllers that call test_json_response without
     * the exit() killing the PHPUnit process.
     *
     * @param array<string, mixed> $data
     */
    public static function testJsonResponse(array $data): void
    {
        /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
        if (!TEST_MODE) {
            return;
        }
        // B-EXIT : mode 'no-exit' pour tests PHPUnit — capture au lieu d'exit.
        // On utilise isset() plutôt que !empty() pour satisfaire phpstan-strict-rules
        // (règle empty.notAllowed). Le flag peut être false (default) ou true.
        if (isset($GLOBALS['_test_no_exit']) && $GLOBALS['_test_no_exit'] === true) {
            $GLOBALS['_test_json_output'] = $data;
            return;
        }
        /** @phpstan-ignore-next-line deadCode.unreachable */
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['_test_mode' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
