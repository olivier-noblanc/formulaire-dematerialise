<?php
declare(strict_types=1);

namespace App\Contract;

interface SecurityInterface
{
    public function generateCsrfToken(): string;
    public function verifyCsrf(): bool;
    public function sendSecurityHeaders(): void;
    public function rateLimitCheck(string $action, int $maxAttempts = 10, int $windowSeconds = 60): bool;
}
