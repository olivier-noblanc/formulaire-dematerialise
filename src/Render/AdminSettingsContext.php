<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Context object for AdminSettingsRenderer::renderContent().
 *
 * Replaces the loose array<string, mixed> $state parameter.
 */
final readonly class AdminSettingsContext
{
    /**
     * @param string               $success
     * @param string               $error
     * @param string               $test
     * @param array<string, mixed>|null $verify_result
     */
    public function __construct(
        public string $success,
        public string $error,
        public string $test,
        public ?array $verify_result,
    ) {
    }
}
