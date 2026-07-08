<?php
declare(strict_types=1);

namespace App\Repository;

final class AdminRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne("SELECT * FROM admins WHERE email = ?", [strtolower($email)]);
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
        $result = $this->fetchOne("SELECT value FROM settings WHERE key = 'admin_email'");
        return $result['value'] ?? '';
    }

    public function getAll(): array
    {
        return $this->fetchAll("SELECT * FROM admins ORDER BY email");
    }

    public function add(string $email, string $addedBy): bool
    {
        return $this->execute(
            "INSERT OR IGNORE INTO admins (email, added_at, added_by) VALUES (?, datetime('now'), ?)",
            [strtolower($email), $addedBy]
        );
    }

    public function remove(string $email): bool
    {
        return $this->execute("DELETE FROM admins WHERE email = ?", [strtolower($email)]);
    }

    public function getPendingRequests(): array
    {
        return $this->fetchAll(
            "SELECT * FROM admin_requests WHERE status = 'pending' ORDER BY requested_at"
        );
    }

    public function approveRequest(string $requestId, string $approvedBy): bool
    {
        $request = $this->fetchOne("SELECT * FROM admin_requests WHERE id = ?", [$requestId]);
        if ($request === null) return false;

        $this->add($request['email'], $approvedBy);
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
