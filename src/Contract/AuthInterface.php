<?php
declare(strict_types=1);

namespace App\Contract;

interface AuthInterface
{
    public function getUser(): string;
    public function isAdmin(): bool;
    public function isAdminEffective(): bool;
    public function isSuperAdmin(): bool;
    public function requireAdmin(): void;
    public function getAdminEmail(): string;
    public function getEmailDomain(): string;
    public function isFormOwner(string $formId, ?string $email = null): bool;
    /** @return array<int, array<string, mixed>> */
    public function getFormOwners(string $formId): array;
    /** @return array<int, array<string, mixed>> */
    public function getOwnedForms(?string $email = null): array;
}
