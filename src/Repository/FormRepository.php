<?php
declare(strict_types=1);

namespace App\Repository;

final class FormRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne("SELECT * FROM forms WHERE id = ?", [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->fetchOne("SELECT * FROM forms WHERE slug = ?", [$slug]);
    }

    public function findActiveBySlug(string $slug): ?array
    {
        return $this->fetchOne("SELECT * FROM forms WHERE slug = ? AND actif = 1", [$slug]);
    }

    public function findIdBySlug(string $slug): ?string
    {
        $result = $this->fetchOne("SELECT id FROM forms WHERE slug = ?", [$slug]);
        return $result !== null ? (string) $result['id'] : null;
    }

    public function findActiveList(): array
    {
        return $this->fetchAll("SELECT id, slug, label, deadline_field FROM forms WHERE actif = 1 ORDER BY label");
    }

    public function findAll(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM forms";
        if ($activeOnly) {
            $sql .= " WHERE actif = 1";
        }
        return $this->fetchAll($sql . " ORDER BY label");
    }

    public function findOwnedBy(string $email): array
    {
        return $this->fetchAll(
            "SELECT f.* FROM forms f JOIN form_owners fo ON fo.form_id = f.id WHERE fo.email = ? ORDER BY f.label",
            [$email]
        );
    }

    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO forms (id, label, slug, description, actif, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['label'], $data['slug'], $data['description'] ?? '', $data['actif'] ?? 1]
        );
        return $id;
    }

    public function update(string $id, array $data): bool
    {
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $id;
        return $this->execute("UPDATE forms SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    }

    public function delete(string $id): bool
    {
        return $this->execute("DELETE FROM forms WHERE id = ?", [$id]);
    }

    public function getFields(string $formId): array
    {
        return $this->fetchAll(
            "SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre",
            [$formId]
        );
    }

    public function getSteps(string $formId): array
    {
        return $this->fetchAll(
            "SELECT * FROM steps WHERE form_id = ? ORDER BY ordre",
            [$formId]
        );
    }

    public function getOwners(string $formId): array
    {
        return $this->fetchAll(
            "SELECT * FROM form_owners WHERE form_id = ? ORDER BY email",
            [$formId]
        );
    }

    public function addOwner(string $formId, string $email): bool
    {
        return $this->execute(
            "INSERT OR IGNORE INTO form_owners (form_id, email, added_at) VALUES (?, ?, datetime('now'))",
            [$formId, strtolower($email)]
        );
    }

    public function removeOwner(string $formId, string $email): bool
    {
        return $this->execute(
            "DELETE FROM form_owners WHERE form_id = ? AND email = ?",
            [$formId, strtolower($email)]
        );
    }

    public function getDateFields(string $formId): array
    {
        return $this->fetchAll(
            "SELECT field_name, label FROM form_fields WHERE form_id = ? AND field_type = 'date' ORDER BY ordre",
            [$formId]
        );
    }

    public function setDeadlineField(string $formId, string $deadlineField): bool
    {
        return $this->execute("UPDATE forms SET deadline_field = ? WHERE id = ?", [$deadlineField, $formId]);
    }

    public function findActiveSlugsAndLabels(): array
    {
        return $this->fetchAll("SELECT slug, label FROM forms WHERE actif = 1 ORDER BY label");
    }

    public function getWorkflowStepsByFormIds(array $formIds): array
    {
        if (empty($formIds)) return [];
        $placeholders = implode(',', array_fill(0, count($formIds), '?'));
        $rows = $this->fetchAll(
            "SELECT st.id as step_id, st.label as step_label, st.ordre, st.actif, st.form_id,
                    GROUP_CONCAT(sr.email, '|') as recipient_emails
             FROM steps st
             LEFT JOIN step_recipients sr ON sr.step_id = st.id
             WHERE st.form_id IN ($placeholders) AND st.actif = 1
             GROUP BY st.id
             ORDER BY st.form_id, st.ordre ASC, st.id ASC",
            $formIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['form_id']][] = $row;
        }
        return $result;
    }

    public function getWorkflowStepsWithTokens(string $formId, string $submissionId): array
    {
        return $this->fetchAll(
            "SELECT st.id, st.label, st.ordre,
                    GROUP_CONCAT(t2.done_at, '|') as dones,
                    GROUP_CONCAT(t2.email, '|') as emails
             FROM steps st
             LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = ?
             WHERE st.form_id = ? AND st.actif = 1
             GROUP BY st.id
             ORDER BY st.ordre, st.id",
            [$submissionId, $formId]
        );
    }

    public function findOwnerEmailById(string $ownerId): ?string
    {
        $result = $this->fetchOne("SELECT email FROM form_owners WHERE id = ?", [$ownerId]);
        return $result !== null ? (string) $result['email'] : null;
    }
}
