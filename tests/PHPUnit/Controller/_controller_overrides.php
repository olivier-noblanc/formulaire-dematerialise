<?php
declare(strict_types=1);

/**
 * Namespace fallthrough overrides for controller tests.
 *
 * Loaded by tests/PHPUnit/Controller/*.php test files BEFORE the controller
 * classes are autoloaded. Defines namespaced stubs for the global functions
 * that would otherwise call `exit;` (test_json_response) — so that the
 * controller's POST handlers can be exercised in-process without killing
 * the PHPUnit runner.
 *
 * Mechanism: PHP resolves unqualified function calls by first looking in the
 * caller's namespace, then falling back to the global namespace. By defining
 * `App\Controller\test_json_response` here, any `test_json_response(...)`
 * call made from within `App\Controller\FormController` (or any other class
 * in `App\Controller`) will resolve to OUR stub — not the global one in
 * src/lib_wrappers.php which calls `exit;`.
 *
 * The stubs:
 *   - Capture the payload into `$GLOBALS['_test_captured_json']` for assertions
 *   - Echo the same JSON the production code would emit (so ob_start captures it)
 *   - Throw `TestJsonCapturedException` to unwind the call stack and bypass
 *     any subsequent `ErrorRenderer::errorPage(...)` or controller-level
 *     `exit;` calls that would otherwise terminate the PHPUnit process.
 *
 * This file is ONLY loaded in test context (via the test bootstrap chain).
 * In production, it is never autoloaded.
 */

namespace App\Tests\Controller {
    /**
     * Thrown by the namespaced test_json_response stubs to abort the controller
     * flow without calling exit;. Tests catch this to retrieve the captured
     * payload via $GLOBALS['_test_captured_json'].
     */
    final class TestJsonCapturedException extends \Exception
    {
        /** @param array<string, mixed> $data */
        public function __construct(public array $data)
        {
            parent::__construct('test_json_response captured — bypassing exit');
        }
    }
}

namespace App\Controller {
    /**
     * Override of the global test_json_response for calls made from within
     * the App\Controller namespace (FormController, ValidateController, etc.).
     */
    function test_json_response(array $data): void
    {
        $GLOBALS['_test_captured_json'] = $data;
        echo json_encode(
            array_merge(['_test_mode' => true], $data),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        throw new \App\Tests\Controller\TestJsonCapturedException($data);
    }
}

namespace App\Security {
    /**
     * Override for calls from App\Security\SecurityService::requireCsrf().
     */
    function test_json_response(array $data): void
    {
        $GLOBALS['_test_captured_json'] = $data;
        echo json_encode(
            array_merge(['_test_mode' => true], $data),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        throw new \App\Tests\Controller\TestJsonCapturedException($data);
    }
}

namespace App\Auth {
    /**
     * Override for calls from App\Auth\AuthService::requireAdmin().
     */
    function test_json_response(array $data): void
    {
        $GLOBALS['_test_captured_json'] = $data;
        echo json_encode(
            array_merge(['_test_mode' => true], $data),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        throw new \App\Tests\Controller\TestJsonCapturedException($data);
    }
}
