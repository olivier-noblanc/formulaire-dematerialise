<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;

final class FormRepository extends BaseRepository
{
    use FormStepsTrait;
    use FormFieldsTrait;
    use FormOwnersTrait;
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
}
