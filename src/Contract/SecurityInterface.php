<?php
declare(strict_types=1);

namespace App\Contract;

interface SecurityInterface
{
    public function generateCsrfToken(): string;
    public function csrfField(): string;
    public function verifyCsrf(): bool;
    public function requireCsrf(): void;
    public function sendSecurityHeaders(): void;
}
