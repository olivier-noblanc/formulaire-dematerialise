<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour la table mail_log (journal des envois email).
 */
final class MailRepository extends BaseRepository
{
    /**
     * @param array{success:bool,error:string,smtp_log:string,status:string} $result
     */
    public function insertLog(
        string $id,
        string $to,
        string $subject,
        array $result,
        string $actor,
        string $ip
    ): bool {
        return $this->execute(
            'INSERT INTO mail_log (id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip)
             VALUES (?, datetime(\'now\'), ?, ?, ?, ?, ?, ?, ?)',
            [$id, $to, $subject, $result['status'], $result['error'], $result['smtp_log'], $actor, $ip]
        );
    }

    /**
     * @return array<int, array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}>
     */
    public function getRecentLogs(int $limit = 30): array
    {
        /** @var array<int, array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, created_at, recipient, subject, status, error_message, smtp_log, actor, ip FROM mail_log ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );
        return $result;
    }

    public function tableExists(): bool
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM sqlite_master WHERE type='table' AND name='mail_log'"
        );
        return ($result['cnt'] ?? 0) > 0;
    }
}
