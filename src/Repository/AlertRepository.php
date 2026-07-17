<?php

declare(strict_types=1);

namespace App\Repository;

final class AlertRepository extends BaseRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function createRule(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$id, $data['form_id'], $data['days_before'], $data['condition_type'], $data['notify_who'], $data['label']]
        );
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRule(string $ruleId, array $data): bool
    {
        return $this->execute(
            'UPDATE alert_rules SET days_before=?, condition_type=?, notify_who=?, label=?, actif=? WHERE id=?',
            [$data['days_before'], $data['condition_type'], $data['notify_who'], $data['label'], $data['actif'], $ruleId]
        );
    }

    public function deleteRule(string $ruleId): bool
    {
        return $this->execute('DELETE FROM alert_rules WHERE id = ?', [$ruleId]);
    }

    /**
     * @return array<int, array{id: string, form_id: string, days_before: int, condition_type: string, notify_who: string, label: string, actif: int, created_at: string, form_label: string, form_slug: string, deadline_field: string}>
     */
    public function getAllWithForm(): array
    {
        /** @var array<int, array{id: string, form_id: string, days_before: int, condition_type: string, notify_who: string, label: string, actif: int, created_at: string, form_label: string, form_slug: string, deadline_field: string}> $result */
        $result = $this->fetchAll(
            'SELECT ar.id, ar.form_id, ar.days_before, ar.condition_type, ar.notify_who, ar.label, ar.actif, ar.created_at,
                    f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM alert_rules ar
             JOIN forms f ON f.id = ar.form_id
             ORDER BY f.label, ar.days_before DESC'
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, rule_id: string, submission_id: string, sent_at: string, message: string|null, form_label: string, rule_label: string|null}>
     */
    public function getLogsWithForm(int $limit = 50): array
    {
        /** @var array<int, array{id: string, rule_id: string, submission_id: string, sent_at: string, message: string|null, form_label: string, rule_label: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT al.id, al.rule_id, al.submission_id, al.sent_at, al.message,
                    f.label as form_label, ar.label as rule_label
             FROM alert_log al
             JOIN submissions s ON s.id = al.submission_id
             JOIN forms f ON f.id = s.form_id
             LEFT JOIN alert_rules ar ON ar.id = al.rule_id
             ORDER BY al.sent_at DESC
             LIMIT ?',
            [$limit]
        );
        return $result;
    }

    public function purgeOldLogs(int $retentionDays): bool
    {
        return $this->execute(
            "DELETE FROM alert_log WHERE sent_at < datetime('now', ?)",
            ["-{$retentionDays} days"]
        );
    }

    /**
     * @param array<int, string> $submissionIds
     */
    public function deleteLogBySubmissionIds(array $submissionIds): int
    {
        if ($submissionIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = $this->pdo()->prepare("DELETE FROM alert_log WHERE submission_id IN ($placeholders)");
        $stmt->execute($submissionIds);
        return $stmt->rowCount();
    }

    public function countPurgeableByCutoff(string $cutoff): int
    {
        /** @var array{cnt: int}|null $result */
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM alert_log al
             JOIN submissions s ON s.id = al.submission_id
             WHERE s.status IN ('valide', 'refuse') AND s.closed_at IS NOT NULL AND s.closed_at < ?",
            [$cutoff]
        );
        return (int) ($result['cnt'] ?? 0);
    }

    public function findLabelById(string $ruleId): ?string
    {
        /** @var array{label: string}|null $result */
        $result = $this->fetchOne('SELECT label FROM alert_rules WHERE id = ?', [$ruleId]);
        return $result !== null ? (string) $result['label'] : null;
    }
}
