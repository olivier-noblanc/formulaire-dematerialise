<?php

declare(strict_types=1);

/**
 * Global test mode helpers.
 *
 * Delegates to App\Core\TestModeService.
 * Loaded by lib_wrappers.php (main loader).
 */

use App\Core\TestModeService;

/**
 * @return array<int, array{to: string, subject: string, body: string, time: string}>
 */
function get_test_mails(): array
{
    return TestModeService::getTestMails();
}
function reset_test_mails(): void
{
    TestModeService::resetTestMails();
}
/**
 * @param array<string, mixed> $data
 */
function test_json_response(array $data): void
{
    TestModeService::testJsonResponse($data);
}
