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
     * @param array<string, mixed>|null $verify_result
     */
    public function __construct(
        public string $success,
        public string $error,
        public string $test,
        public ?array $verify_result,
    ) {}

    /**
     * Build from legacy array state (BC for lib_wrappers).
     *
     * @param array<string, mixed> $state
     */
    public static function fromLegacyArray(array $state): self
    {
        return new self(
            success: (string) ($state['success'] ?? ''),
            error: (string) ($state['error'] ?? ''),
            test: (string) ($state['test'] ?? ''),
            verify_result: $state['verify_result'] ?? null,
        );
    }
}
