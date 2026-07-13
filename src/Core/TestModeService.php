<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Test mode utilities — email capture and JSON responses for E2E tests.
 */
final class TestModeService
{
    /**
     * Retrieve captured test emails.
     * @return array<string, mixed>
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
     * @param array<string, mixed> $data
     */
    public static function testJsonResponse(array $data): void
    {
        /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
        if (!TEST_MODE) {
            return;
        }
        /** @phpstan-ignore-next-line deadCode.unreachable */
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['_test_mode' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
