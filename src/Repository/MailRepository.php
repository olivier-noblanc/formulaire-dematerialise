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
     * Revendique atomiquement jusqu'à $limit lignes réessayables de l'outbox.
     *
     * `BEGIN EXCLUSIVE` sérialise les workers : deux passages de cron concurrents
     * ne peuvent pas revendiquer la même ligne (revendication atomique). Chaque
     * ligne revendue voit son `attempts` incrémenté et son `next_retry_at`
     * repoussé à $leaseUntil (CAS sur status+attempts) — verrou le temps de
     * l'envoi, les autres workers l'ignorent jusqu'à expiration du bail.
     *
     * Candidates :
     *   - status `error` dû (`next_retry_at` NULL ou <= now) ;
     *   - status `pending` orphelin (`created_at` <= now - staleSeconds) — le
     *     process qui a écrit la ligne est mort avant la finalisation.
     * Dans les deux cas : `attempts` < $maxAttempts (sinon échec définitif).
     *
     * @return list<array{id: string, recipient: string, subject: string, body_html: string|null, attempts: int}>
     */
    public function claimRetryable(int $maxAttempts, int $limit, string $leaseUntil, int $staleSeconds): array
    {
        $pdo = $this->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('BEGIN EXCLUSIVE');
        }
        try {
            $select = $pdo->prepare(
                "SELECT id, recipient, subject, body_html, attempts, status
                   FROM mail_log
                  WHERE attempts < ?
                    AND (
                        (status = ? AND (next_retry_at IS NULL OR next_retry_at <= datetime('now')))
                        OR (status = ? AND created_at <= datetime('now', ?))
                    )
                  ORDER BY created_at ASC
                  LIMIT ?"
            );
            $select->execute([
                $maxAttempts,
                MailStatus::Error->value,
                MailStatus::Pending->value,
                '-' . $staleSeconds . ' seconds',
                $limit,
            ]);
            /** @var list<array{id: string, recipient: string, subject: string, body_html: string|null, attempts: int|string, status: string}> $rows */
            $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
            // Libérer le statement avant l'UPDATE (règle SQLITE_LOCKED intra-processus).
            $select = null;

            $update = $pdo->prepare(
                'UPDATE mail_log SET attempts = attempts + 1, next_retry_at = ?
                  WHERE id = ? AND status = ? AND attempts = ?'
            );

            $claimed = [];
            foreach ($rows as $row) {
                $update->execute([$leaseUntil, $row['id'], $row['status'], $row['attempts']]);
                // CAS : un autre worker (ou une finalisation concurrente) a pu
                // modifier la ligne entre le SELECT et l'UPDATE → on ne revendique
                // que si exactement une ligne correspondait encore.
                if ($update->rowCount() === 1) {
                    $claimed[] = [
                        'id' => $row['id'],
                        'recipient' => $row['recipient'],
                        'subject' => $row['subject'],
                        'body_html' => $row['body_html'],
                        'attempts' => (int) $row['attempts'] + 1,
                    ];
                }
            }
            $update = null;

            if ($ownTransaction) {
                $pdo->exec('COMMIT');
            }
            return $claimed;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // @silent-ok: cleanup fallback — l'exception d'origine est relancée
                }
            }
            throw $e;
        }
    }

    /**
     * Compte les lignes de mail_log dans un statut donné.
     */
    public function countByStatus(MailStatus $status): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS cnt FROM mail_log WHERE status = ?',
            [$status->value]
        );
        return (int) ($row['cnt'] ?? 0);
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

    /**
     * Métadonnées des emails envoyés à un destinataire (export RGPD).
     *
     * On n'expose PAS `body_html` (contenu potentiellement personnel et déjà
     * couvert par l'export des soumissions/tokens) — uniquement l'enveloppe.
     *
     * @return list<array{id: string, created_at: string, subject: string, status: string, attempts: int}>
     */
    public function findByRecipient(string $email, int $limit = 200): array
    {
        $rows = $this->fetchAll(
            'SELECT id, created_at, subject, status, attempts FROM mail_log
              WHERE recipient = ? ORDER BY created_at DESC LIMIT ?',
            [$email, $limit]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'subject' => (string) ($row['subject'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'attempts' => (int) ($row['attempts'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Anonymise les emails d'un destinataire (droit à l'effacement RGPD).
     *
     * Le destinataire est remplacé et le corps HTML est purgé (il contient les
     * données personnelles). La ligne est conservée pour la traçabilité d'envoi.
     *
     * @return int Nombre de lignes anonymisées.
     */
    public function anonymizeByRecipient(string $email, string $replacement): int
    {
        $stmt = $this->pdo()->prepare('UPDATE mail_log SET recipient = ?, body_html = NULL WHERE recipient = ?');
        $stmt->execute([$replacement, $email]);
        return $stmt->rowCount();
    }

    /**
     * Purge les emails antérieurs au cutoff (conservation limitée RGPD).
     *
     * @return int Nombre de lignes supprimées.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo()->prepare('DELETE FROM mail_log WHERE created_at < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }
}
