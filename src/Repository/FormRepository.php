<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FieldType;
use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;

final class FormRepository extends BaseRepository
{
    /**
     * @return array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null
     */
    public function findById(string $id): ?array
    {
        /** @var array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null $result */
        $result = $this->fetchOne('SELECT id, slug, label, description, actif, created_at, deadline_field FROM forms WHERE id = ?', [$id]);
        return $result;
    }

    /**
     * @return array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        /** @var array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null $result */
        $result = $this->fetchOne('SELECT id, slug, label, description, actif, created_at, deadline_field FROM forms WHERE slug = ? AND actif = 1', [$slug]);
        return $result;
    }

    public function findIdBySlug(string $slug): ?string
    {
        $result = $this->fetchOne('SELECT id FROM forms WHERE slug = ?', [$slug]);
        return $result !== null ? (string) $result['id'] : null;
    }

    /**
     * @return array<int, array{id: string, slug: string, label: string, deadline_field: string}>
     */
    public function findActiveList(): array
    {
        /** @var array<int, array{id: string, slug: string, label: string, deadline_field: string}> $result */
        $result = $this->fetchAll('SELECT id, slug, label, deadline_field FROM forms WHERE actif = 1 ORDER BY label');
        return $result;
    }

    /**
     * Formulaires actifs avec nombre de soumissions (welcome state).
     *
     * @return array<int, array{id: string, slug: string, label: string, description: string|null, nb_soumissions: int}>
     */
    public function findActiveWithSubmissionCounts(int $limit = 3): array
    {
        /** @var array<int, array{id: string, slug: string, label: string, description: string|null, nb_soumissions: int}> $result */
        $result = $this->fetchAll(
            'SELECT f.id, f.slug, f.label, f.description, COUNT(s.id) AS nb_soumissions
             FROM forms f
             LEFT JOIN submissions s ON s.form_id = f.id
             WHERE f.actif = 1
             GROUP BY f.id, f.slug, f.label, f.description
             ORDER BY nb_soumissions DESC, f.label ASC
             LIMIT ' . (int) $limit
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}>
     */
    public function findAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT id, slug, label, description, actif, created_at, deadline_field FROM forms';
        if ($activeOnly) {
            $sql .= ' WHERE actif = 1';
        }
        /** @var array<int, array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}> $result */
        $result = $this->fetchAll($sql . ' ORDER BY label');
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $id = \generate_uuid();
        $this->execute(
            "INSERT INTO forms (id, label, slug, description, actif, created_at, deadline_field) VALUES (?, ?, ?, ?, ?, datetime('now'), ?)",
            [$id, $data['label'], $data['slug'], $data['description'] ?? '', $data['actif'] ?? 1, $data['deadline_field'] ?? '']
        );
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        $allowed = ['label', 'slug', 'description', 'actif', 'deadline_field'];
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $fields[] = "`$key` = ?";
            $params[] = $value;
        }
        if ($fields === []) {
            return false;
        }
        $params[] = $id;
        return $this->execute('UPDATE forms SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    /**
     * @return array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public function getFields(string $formId): array
    {
        /** @var array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ? ORDER BY ordre',
            [$formId]
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, form_id: string, label: string, ordre: int, actif: int, condition: string}>
     */
    public function getSteps(string $formId): array
    {
        /** @var array<int, array{id: string, form_id: string, label: string, ordre: int, actif: int, condition: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, form_id, label, ordre, actif, condition FROM steps WHERE form_id = ? ORDER BY ordre',
            [$formId]
        );
        return $result;
    }

    /**
     * @return array<int, array{id: string, form_id: string, label: string, ordre: int, actif: int, condition: string, recipient_emails: string|null}>
     */
    public function getStepsWithRecipients(string $formId): array
    {
        /** @var array<int, array{id: string, form_id: string, label: string, ordre: int, actif: int, condition: string, recipient_emails: string|null}> $result */
        $result = $this->fetchAll(
            "SELECT s.id, s.form_id, s.label, s.ordre, s.actif, s.condition, GROUP_CONCAT(sr.email, '|') as recipient_emails
             FROM steps s
             LEFT JOIN step_recipients sr ON sr.step_id = s.id
             WHERE s.form_id = ?
             GROUP BY s.id
             ORDER BY s.ordre",
            [$formId]
        );
        return $result;
    }

    /**
     * @return array<int, array{field_name: string, label: string}>
     */
    public function getDateFields(string $formId): array
    {
        /** @var array<int, array{field_name: string, label: string}> $result */
        $result = $this->fetchAll(
            "SELECT field_name, label FROM form_fields WHERE form_id = ? AND field_type = '" . FieldType::Date->value . "' AND filled_by = '" . FilledBy::Demandeur->value . "' ORDER BY ordre",
            [$formId]
        );
        return $result;
    }

    public function setDeadlineField(string $formId, string $deadlineField): bool
    {
        return $this->execute('UPDATE forms SET deadline_field = ? WHERE id = ?', [$deadlineField, $formId]);
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public function findActiveSlugsAndLabels(): array
    {
        /** @var array<int, array{slug: string, label: string}> $result */
        $result = $this->fetchAll('SELECT slug, label FROM forms WHERE actif = 1 ORDER BY label');
        return $result;
    }

    /**
     * @param array<int, string> $formIds
     * @return array<string, array<int, array{step_id: string, step_label: string, ordre: int, actif: int, form_id: string, recipient_emails: string}>>
     */
    public function getWorkflowStepsByFormIds(array $formIds): array
    {
        if ($formIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($formIds), '?'));
        /** @var array<int, array{step_id: string, step_label: string, ordre: int, actif: int, form_id: string, recipient_emails: string}> $rows */
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
     * @return array<int, array{id: string, label: string, ordre: int, dones: string|null, emails: string|null}>
     */
    public function getWorkflowStepsWithTokens(string $formId, string $submissionId): array
    {
        /** @var array<int, array{id: string, label: string, ordre: int, dones: string|null, emails: string|null}> $result */
        $result = $this->fetchAll(
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
        return $result;
    }

    /**
     * Récupère les steps actives d'un formulaire avec recipients agrégés (pipe-separated).
     * Inclut la colonne `condition` (nécessaire pour WorkflowEngine::createTokensForGroup).
     * Variante mono-form_id de getWorkflowStepsByFormIds().
     *
     * @return list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}>
     */
    public function findWorkflowStepsForEngine(string $formId): array
    {
        /** @var list<array{step_id: string, step_label: string, ordre: int, actif: int, condition: string, recipient_emails: string}> $result */
        $result = $this->fetchAll(
            "SELECT st.id as step_id, st.label as step_label, st.ordre, st.actif, st.condition,
                   GROUP_CONCAT(sr.email, '|') as recipient_emails
            FROM steps st
            LEFT JOIN step_recipients sr ON sr.step_id = st.id
            WHERE st.form_id = ? AND st.actif = 1
            GROUP BY st.id
            ORDER BY st.ordre ASC, st.id ASC",
            [$formId]
        );
        return $result;
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
            [$id, $data['form_id'], $data['label'], $data['field_type'] ?? FieldType::Text->value, $data['field_name'], $data['options'] ?? null, $data['hint'] ?? '', $data['required'] ?? 0, $data['ordre'] ?? 0, $data['card_group'] ?? 'Général', $data['filled_by'] ?? FilledBy::Demandeur->value, $data['validator_step'] ?? '', $data['visibility'] ?? 'all']
        );
        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateField(string $fieldId, array $data): bool
    {
        $allowed = ['label', 'field_type', 'field_name', 'options', 'hint', 'required', 'ordre', 'card_group', 'filled_by', 'validator_step', 'visibility', 'condition'];
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[] = "`$key` = ?";
                $params[] = $value;
            }
        }
        if ($fields === '' || $fields === null || $fields === '0') {
            return false;
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
        $allowed = ['label', 'ordre', 'actif', 'condition'];
        $fields = [];
        $params = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[] = "`$key` = ?";
                $params[] = $value;
            }
        }
        if ($fields === '' || $fields === null || $fields === '0') {
            return false;
        }
        $params[] = $stepId;
        return $this->execute('UPDATE steps SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
    }

    public function deleteStep(string $stepId): bool
    {
        $this->execute('DELETE FROM tokens WHERE step_id = ?', [$stepId]);
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
            [$id, $formId, strtolower(trim($email))]
        );
        return $id;
    }

    public function deleteOwnerById(string $ownerId): bool
    {
        return $this->execute('DELETE FROM form_owners WHERE id = ?', [$ownerId]);
    }

    // ── Cascade delete ──────────────────────────────────────────

    /**
     * @return array<int, array{label: string, total: int, en_cours: int, valide: int, refuse: int}>
     */
    public function getSubmissionCounts(): array
    {
        /** @var array<int, array{label: string, total: int, en_cours: int, valide: int, refuse: int}> $result */
        $result = $this->fetchAll(
            "SELECT f.label, COUNT(s.id) as total,
                    SUM(CASE WHEN s.status = '" . SubmissionStatus::EnCours->value . "' THEN 1 ELSE 0 END) as en_cours,
                    SUM(CASE WHEN s.status = '" . SubmissionStatus::Valide->value . "' THEN 1 ELSE 0 END) as valide,
                    SUM(CASE WHEN s.status = '" . SubmissionStatus::Refuse->value . "' THEN 1 ELSE 0 END) as refuse
             FROM forms f
             LEFT JOIN submissions s ON s.form_id = f.id
             GROUP BY f.id
             ORDER BY total DESC"
        );
        return $result;
    }

    public function deleteCascade(string $formId): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            // Supprimer les données enfants des soumissions
            $this->execute('DELETE FROM submission_validator_data WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = ?)', [$formId]);
            $this->execute('DELETE FROM alert_log WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = ?)', [$formId]);
            $this->execute('DELETE FROM tokens WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = ?)', [$formId]);
            $this->execute('DELETE FROM attachments WHERE submission_id IN (SELECT id FROM submissions WHERE form_id = ?)', [$formId]);
            $this->execute('DELETE FROM submissions WHERE form_id = ?', [$formId]);
            // Supprimer les structures du formulaire
            $this->execute('DELETE FROM step_recipients WHERE step_id IN (SELECT id FROM steps WHERE form_id = ?)', [$formId]);
            $this->execute('DELETE FROM form_fields WHERE form_id = ?', [$formId]);
            $this->execute('DELETE FROM form_owners WHERE form_id = ?', [$formId]);
            $this->execute('DELETE FROM steps WHERE form_id = ?', [$formId]);
            $this->execute('DELETE FROM forms WHERE id = ?', [$formId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
                [$newFieldId, $newId, $f['label'], $f['field_type'], $f['field_name'], $f['options'], $f['hint'] ?? '', $f['required'], $f['ordre'], $f['card_group'], $f['filled_by'] ?? FilledBy::Demandeur->value, $f['validator_step'] ?? '', $f['visibility'] ?? 'all']
            );
        }

        foreach ($this->getSteps($sourceId) as $s) {
            $newStepId = \generate_uuid();
            $this->execute(
                'INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)',
                [$newStepId, $newId, $s['label'], $s['ordre'], $s['actif'], (string) ($s['condition'] ?? '')]
            );

            $recips = $this->fetchAll('SELECT id, step_id, email FROM step_recipients WHERE step_id = ?', [$s['id']]);
            foreach ($recips as $recip) {
                $newRecipId = \generate_uuid();
                $this->execute(
                    'INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)',
                    [$newRecipId, $newStepId, $recip['email']]
                );
            }
        }
    }

    /**
     * @return array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public function getValidatorFields(string $formId, ?string $stepId = null): array
    {
        $sql = "SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ? AND filled_by = '" . FilledBy::Validator->value . "'";
        $params = [$formId];

        if ($stepId !== null && $stepId !== '') {
            $stepLabel = $this->getStepLabel($stepId) ?? '';

            $sql .= " AND (validator_step = ? OR validator_step = ? OR validator_step = '')";
            $params[] = $stepId;
            $params[] = $stepLabel;
        }

        $sql .= ' ORDER BY ordre, id';
        /** @var array<int, array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $this->fetchAll($sql, $params);
        return $result;
    }

    public function getStepLabel(string $stepId): ?string
    {
        $result = $this->fetchOne('SELECT label FROM steps WHERE id = ?', [$stepId]);
        return $result !== null ? (string) $result['label'] : null;
    }

    /**
     * Récupère les owners (id, email, added_at) d'un formulaire.
     * Utilisé par WorkflowEngine::resolveDynamicRecipient() pour {{owner}}.
     *
     * @return list<array{id: string, email: string, added_at: string}>
     */
    public function findOwnersByFormId(string $formId): array
    {
        /** @var list<array{id: string, email: string, added_at: string}> $result */
        $result = $this->fetchAll(
            'SELECT id, email, added_at FROM form_owners WHERE form_id = ? ORDER BY email',
            [$formId]
        );
        return $result;
    }

    /**
     * Vérifie si un email est owner d'un formulaire (case-insensitive).
     * Utilisé par AuthService::isFormOwner().
     */
    public function isOwnerByEmail(string $formId, string $email): bool
    {
        $result = $this->fetchOne(
            'SELECT 1 FROM form_owners WHERE form_id = ? AND LOWER(email) = LOWER(?)',
            [$formId, $email]
        );
        return $result !== null;
    }

    /**
     * Récupère les formulaires owned par un email (case-insensitive).
     * Utilisé par AuthService::getOwnedForms().
     *
     * @return list<array{id: string, label: string, slug: string, actif: int}>
     */
    public function findOwnedFormsByEmail(string $email): array
    {
        /** @var list<array{id: string, label: string, slug: string, actif: int}> $result */
        $result = $this->fetchAll(
            'SELECT f.id, f.label, f.slug, f.actif
            FROM forms f
            JOIN form_owners fo ON fo.form_id = f.id
            WHERE LOWER(fo.email) = LOWER(?)
            ORDER BY f.label',
            [$email]
        );
        return $result;
    }

    /**
     * Compte les formulaires avec un slug donné (excluant un form_id optionnel).
     * Utilisé par SlugHelper::generateSlug() pour vérifier l'unicité du slug.
     */
    public function countBySlug(string $slug, ?string $excludeFormId = null): int
    {
        $sql = 'SELECT COUNT(*) as cnt FROM forms WHERE slug = ?';
        $params = [$slug];
        if ($excludeFormId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeFormId;
        }
        /** @var array{cnt: int|string|null}|null $result */
        $result = $this->fetchOne($sql, $params);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Récupère les champs d'un formulaire filtrés par filled_by (optionnel).
     * Variante de getFields() avec filtre filled_by — utilisée par FieldService::getFields().
     *
     * @return list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}>
     */
    public function getFieldsByFilledBy(string $formId, ?string $filledBy = null): array
    {
        $sql = 'SELECT id, form_id, label, field_type, field_name, options, hint, required, ordre, card_group, filled_by, validator_step, visibility, condition FROM form_fields WHERE form_id = ?';
        $params = [$formId];
        if ($filledBy !== null) {
            $sql .= ' AND filled_by = ?';
            $params[] = $filledBy;
        }
        $sql .= ' ORDER BY ordre, id';
        /** @var list<array{id: string, form_id: string, label: string, field_type: string, field_name: string, options: string|null, hint: string, required: int, ordre: int, card_group: string, filled_by: string, validator_step: string, visibility: string, condition: string}> $result */
        $result = $this->fetchAll($sql, $params);
        return $result;
    }

    /**
     * Récupère les infos d'un champ (label, field_type) par son field_name.
     * Utilisé par FieldService::saveValidatorData() pour récupérer le label/type
     * avant l'UPSERT dans submission_validator_data.
     *
     * @return array{label: string, field_type: string}|null
     */
    public function findFieldLabelAndTypeByName(string $fieldName): ?array
    {
        /** @var array{label: string, field_type: string}|null $result */
        $result = $this->fetchOne(
            'SELECT label, field_type FROM form_fields WHERE field_name = ?',
            [$fieldName]
        );
        return $result;
    }
}
