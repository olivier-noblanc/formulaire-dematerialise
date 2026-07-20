<?php

declare(strict_types=1);

namespace App\Repository;

final class AdminRepository extends BaseRepository
{
    public function __construct(
        \App\Core\Database $database,
        private readonly SettingsRepository $settingsRepository,
    ) {
        parent::__construct($database);
    }

    /**
     * @return array{id: string, email: string, added_at: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        /** @var array{id: string, email: string, added_at: string}|null $result */
        $result = $this->fetchOne('SELECT id, email, added_at FROM admins WHERE email = ?', [strtolower($email)]);
        return $result;
    }

    public function isAdmin(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function isSuperAdmin(string $email): bool
    {
        $adminEmail = $this->getSuperAdminEmail();
        return strtolower($email) === strtolower($adminEmail);
    }

    public function getSuperAdminEmail(): string
    {
        return $this->settingsRepository->get('admin_email') ?? '';
    }

    /**
     * @return array<int, array{email: string}>
     */
    public function getAll(): array
    {
        /** @var array<int, array{email: string}> $result */
        $result = $this->fetchAll('SELECT email FROM admins ORDER BY email');
        return $result;
    }

    /**
     * @return array{email: string, created_at: string}|null
     */
    public function findByToken(string $token): ?array
    {
        /** @var array{email: string, created_at: string}|null $result */
        $result = $this->fetchOne("SELECT email, created_at FROM admin_requests WHERE token = ? AND status = 'pending'", [$token]);
        return $result;
    }

    /**
     * @return array<int, array{id: string, email: string, requested_at: string, status: string, token: string}>
     */
    public function getPendingRequestsDesc(): array
    {
        /** @var array<int, array{id: string, email: string, requested_at: string, status: string, token: string}> $result */
        $result = $this->fetchAll("SELECT id, email, requested_at, status, token FROM admin_requests WHERE status = 'pending' ORDER BY requested_at DESC");
        return $result;
    }

    /**
     * @return array{id: string, email: string, requested_at: string, status: string, token: string}|null
     */
    public function findPendingByEmail(string $email): ?array
    {
        /** @var array{id: string, email: string, requested_at: string, status: string, token: string}|null $result */
        $result = $this->fetchOne("SELECT id, email, requested_at, status, token FROM admin_requests WHERE email = ? AND status = 'pending' ORDER BY requested_at DESC LIMIT 1", [strtolower($email)]);
        return $result;
    }

    public function add(string $email): bool
    {
        return $this->execute(
            "INSERT OR IGNORE INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))",
            [\generate_uuid(), strtolower($email)]
        );
    }

    public function approveRequest(string $requestId, string $approvedBy): bool
    {
        /** @var array{id: string, email: string, requested_at: string, status: string, token: string}|null $request */
        $request = $this->fetchOne('SELECT id, email, requested_at, status, token FROM admin_requests WHERE id = ?', [$requestId]);
        if ($request === null) {
            return false;
        }

        $this->add($request['email']);
        return $this->execute(
            "UPDATE admin_requests SET status = 'approved', reviewed_at = datetime('now'), reviewed_by = ? WHERE id = ?",
            [$approvedBy, $requestId]
        );
    }

    public function rejectRequest(string $requestId, string $rejectedBy): bool
    {
        return $this->execute(
            "UPDATE admin_requests SET status = 'rejected', reviewed_at = datetime('now'), reviewed_by = ? WHERE id = ?",
            [$rejectedBy, $requestId]
        );
    }
}
