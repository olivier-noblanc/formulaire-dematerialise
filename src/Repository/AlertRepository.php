<?php
declare(strict_types=1);

namespace App\Repository;

final class AlertRepository extends BaseRepository
{
    public function createRule(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO alert_rules (id, form_id, days_before, condition_type, notify_who, label, actif) VALUES (?, ?, ?, ?, ?, ?, 1)",
            [$id, $data['form_id'], $data['days_before'], $data['condition_type'], $data['notify_who'], $data['label']]
        );
        return $id;
    }

    public function updateRule(string $ruleId, array $data): bool
    {
        return $this->execute(
            "UPDATE alert_rules SET days_before=?, condition_type=?, notify_who=?, label=?, actif=? WHERE id=?",
            [$data['days_before'], $data['condition_type'], $data['notify_who'], $data['label'], $data['actif'], $ruleId]
        );
    }

    public function deleteRule(string $ruleId): bool
    {
        return $this->execute("DELETE FROM alert_rules WHERE id = ?", [$ruleId]);
    }

    public function getAllWithForm(): array
    {
        return $this->fetchAll(
            "SELECT ar.*, f.label as form_label, f.slug as form_slug, f.deadline_field
             FROM alert_rules ar
             JOIN forms f ON f.id = ar.form_id
             ORDER BY f.label, ar.days_before DESC"
        );
    }

    public function getLogsWithForm(int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT al.*, f.label as form_label, ar.label as rule_label
             FROM alert_log al
             JOIN submissions s ON s.id = al.submission_id
             JOIN forms f ON f.id = s.form_id
             LEFT JOIN alert_rules ar ON ar.id = al.rule_id
             ORDER BY al.sent_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    public function purgeOldLogs(int $retentionDays): bool
    {
        return $this->execute(
            "DELETE FROM alert_log WHERE sent_at < datetime('now', ?)",
            ["-{$retentionDays} days"]
        );
    }

    public function findLabelById(string $ruleId): ?string
    {
        $result = $this->fetchOne("SELECT label FROM alert_rules WHERE id = ?", [$ruleId]);
        return $result !== null ? (string) $result['label'] : null;
    }
}
