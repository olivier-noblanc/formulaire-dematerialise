<?php
declare(strict_types=1);

namespace App\Contract;

interface AuthInterface
{
    public function getUser(): ?string;
    public function isAdmin(): bool;
    public function isSuperAdmin(): bool;
    public function requireAdmin(): void;
}
