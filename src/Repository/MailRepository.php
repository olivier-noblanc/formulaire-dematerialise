<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\MailStatus;

/**
 * Repository pour la table mail_log (journal des envois email + outbox write-ahead).
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
     * Write-ahead : insère la ligne `pending` AVANT toute tentative SMTP, avec
     * le corps HTML complet. Retourne true si la ligne est bien persistée.
     */
    public function insertPending(
        string $id,
        string $to,
        string $subject,
        string $bodyHtml,
        string $actor,
        string $ip
    ): bool {
        return $this->execute(
            "INSERT INTO mail_log (id, created_at, recipient, subject, body_html, status, error_message, smtp_log, attempts, next_retry_at, actor, ip)
             VALUES (?, datetime('now'), ?, ?, ?, ?, '', '', 0, NULL, ?, ?)",
            [$id, $to, $subject, $bodyHtml, MailStatus::Pending->value, $actor, $ip]
        );
    }

    /**
     * Met à jour la ligne d'outbox avec le résultat final d'une tentative.
     */
    public function finalize(
        string $id,
        MailStatus $status,
        string $error,
        string $smtpLog,
        int $attempts,
        ?string $nextRetryAt
    ): bool {
        return $this->execute(
            'UPDATE mail_log SET status = ?, error_message = ?, smtp_log = ?, attempts = ?, next_retry_at = ? WHERE id = ?',
            [$status->value, $error, $smtpLog, $attempts, $nextRetryAt, $id]
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
        return ((int) ($result['cnt'] ?? 0)) > 0;
    }
}
