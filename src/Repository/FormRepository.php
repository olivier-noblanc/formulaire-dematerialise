<?php

declare(strict_types=1);

namespace App\Repository;

final class FormRepository extends BaseRepository
{
    public function findById(string $id): ?array
    {
        return $this->fetchOne('SELECT * FROM forms WHERE id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->fetchOne('SELECT * FROM forms WHERE slug = ?', [$slug]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        return $this->fetchOne('SELECT * FROM forms WHERE slug = ? AND actif = 1', [$slug]);
    }

    public function findIdBySlug(string $slug): ?string
    {
        $result = $this->fetchOne('SELECT id FROM forms WHERE slug = ?', [$slug]);
        return $result !== null ? (string) $result['id'] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveList(): array
    {
        return $this->fetchAll('SELECT id, slug, label, deadline_field FROM forms WHERE actif = 1 ORDER BY label');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM forms';
        if ($activeOnly) {
            $sql .= ' WHERE actif = 1';
        }
        return $this->fetchAll($sql . ' ORDER BY label');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOwnedBy(string $email): array
    {
        return $this->fetchAll(
            'SELECT f.* FROM forms f JOIN form_owners fo ON fo.form_id = f.id WHERE fo.email = ? ORDER BY f.label',
            [$email]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO forms (id, label, slug, description, actif, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))",
            [$id, $data['label'], $data['slug'], $data['description'] ?? '', $data['actif'] ?? 1]
        );
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            $fields[] = "`$key` = ?";
            $params[] = $value;
        }
        $params[] = $id;
        return $this->execute('UPDATE forms SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public function delete(string $id): bool
    {
        return $this->execute('DELETE FROM forms WHERE id = ?', [$id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFields(string $formId): array
    {
        return $this->fetchAll(
            'SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre',
            [$formId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSteps(string $formId): array
    {
        return $this->fetchAll(
            'SELECT * FROM steps WHERE form_id = ? ORDER BY ordre',
            [$formId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOwners(string $formId): array
    {
        return $this->fetchAll(
            'SELECT * FROM form_owners WHERE form_id = ? ORDER BY email',
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
            'DELETE FROM form_owners WHERE form_id = ? AND email = ?',
            [$formId, strtolower($email)]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDateFields(string $formId): array
    {
        return $this->fetchAll(
            "SELECT field_name, label FROM form_fields WHERE form_id = ? AND field_type = 'date' ORDER BY ordre",
            [$formId]
        );
    }

    public function setDeadlineField(string $formId, string $deadlineField): bool
    {
        return $this->execute('UPDATE forms SET deadline_field = ? WHERE id = ?', [$deadlineField, $formId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveSlugsAndLabels(): array
    {
        return $this->fetchAll('SELECT slug, label FROM forms WHERE actif = 1 ORDER BY label');
    }

    /**
     * @param array<int, string> $formIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getWorkflowStepsByFormIds(array $formIds): array
    {
        if ($formIds === []) {
            return [];
        }
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

    /**
     * @return array<int, array<string, mixed>>
     */
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
        $result = $this->fetchOne('SELECT email FROM form_owners WHERE id = ?', [$ownerId]);
        return $result !== null ? (string) $result['email'] : null;
    }

    // ── Field CRUD ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    public function createField(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $data['form_id'], $data['label'], $data['field_type'] ?? 'text', $data['field_name'], $data['options'] ?? null, $data['hint'] ?? '', $data['required'] ?? 0, $data['ordre'] ?? 0, $data['card_group'] ?? 'Général', $data['filled_by'] ?? 'demandeur', $data['validator_step'] ?? '', $data['visibility'] ?? 'all']
        );
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateField(string $fieldId, array $data): bool
    {
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            $fields[] = "`$key` = ?";
            $params[] = $value;
        }
        $params[] = $fieldId;
        return $this->execute('UPDATE form_fields SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public function deleteField(string $fieldId): bool
    {
        return $this->execute('DELETE FROM form_fields WHERE id = ?', [$fieldId]);
    }

    // ── Step CRUD ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    public function createStep(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $data['form_id'], $data['label'], $data['ordre'] ?? 0, $data['actif'] ?? 1, $data['condition'] ?? '']
        );
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStep(string $stepId, array $data): bool
    {
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            $fields[] = "`$key` = ?";
            $params[] = $value;
        }
        $params[] = $stepId;
        return $this->execute('UPDATE steps SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public function deleteStep(string $stepId): bool
    {
        $this->execute('DELETE FROM step_recipients WHERE step_id = ?', [$stepId]);
        return $this->execute('DELETE FROM steps WHERE id = ?', [$stepId]);
    }

    // ── Recipient CRUD ──────────────────────────────────────────

    public function createRecipient(string $stepId, string $email): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)',
            [$id, $stepId, $email]
        );
        return $id;
    }

    public function deleteRecipient(string $recipientId): bool
    {
        return $this->execute('DELETE FROM step_recipients WHERE id = ?', [$recipientId]);
    }

    public function findRecipientStepId(string $recipientId): ?string
    {
        $result = $this->fetchOne('SELECT step_id FROM step_recipients WHERE id = ?', [$recipientId]);
        return $result !== null ? (string) $result['step_id'] : null;
    }

    // ── Owner CRUD (by id) ──────────────────────────────────────

    public function createOwnerById(string $formId, string $email): string
    {
        $id = \generate_uuid();
        $this->execute(
            'INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)',
            [$id, $formId, $email]
        );
        return $id;
    }

    public function deleteOwnerById(string $ownerId): bool
    {
        return $this->execute('DELETE FROM form_owners WHERE id = ?', [$ownerId]);
    }

    // ── Cascade delete ──────────────────────────────────────────

    public function deleteCascade(string $formId): void
    {
        $this->execute('DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = ?)', [$formId]);
        $this->execute('DELETE FROM form_fields WHERE form_id = ?', [$formId]);
        $this->execute('DELETE FROM form_owners WHERE form_id = ?', [$formId]);
        $this->execute('DELETE FROM steps WHERE form_id = ?', [$formId]);
        $this->execute('DELETE FROM forms WHERE id = ?', [$formId]);
    }

    // ── Duplicate ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $srcForm
     */
    public function duplicate(string $sourceId, string $newId, string $newLabel, string $newSlug, array $srcForm): void
    {
        $this->execute(
            'INSERT INTO forms (id, slug, label, description, actif, deadline_field) VALUES (?, ?, ?, ?, 1, ?)',
            [$newId, $newSlug, $newLabel, $srcForm['description'], $srcForm['deadline_field']]
        );

        foreach ($this->getFields($sourceId) as $f) {
            $newFieldId = \generate_uuid();
            $this->execute(
                'INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$newFieldId, $newId, $f['label'], $f['field_type'], $f['field_name'], $f['options'], $f['hint'] ?? '', $f['required'], $f['ordre'], $f['card_group'], $f['filled_by'] ?? 'demandeur', $f['validator_step'] ?? '', $f['visibility'] ?? 'all']
            );
        }

        foreach ($this->getSteps($sourceId) as $s) {
            $newStepId = \generate_uuid();
            $this->execute(
                'INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)',
                [$newStepId, $newId, $s['label'], $s['ordre'], $s['actif'], (string) ($s['condition'] ?? '')]
            );

            $recips = $this->fetchAll('SELECT * FROM step_recipients WHERE step_id = ?', [$s['id']]);
            foreach ($recips as $recip) {
                $newRecipId = \generate_uuid();
                $this->execute(
                    'INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)',
                    [$newRecipId, $newStepId, $recip['email']]
                );
            }
        }
    }
}
