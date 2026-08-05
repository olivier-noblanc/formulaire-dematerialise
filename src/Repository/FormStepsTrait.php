<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * @internal Trait utilisé par FormRepository pour limiter la taille du fichier principal.
 *
 * @method array<string, mixed>|null fetchOne(string $sql, array<int, mixed> $params = [])
 * @method array<int, array<string, mixed>> fetchAll(string $sql, array<int, mixed> $params = [])
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 */
trait FormStepsTrait
{
    // ── Step queries ─────────────────────────────────────────────

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

    public function getStepLabel(string $stepId): ?string
    {
        $result = $this->fetchOne('SELECT label FROM steps WHERE id = ?', [$stepId]);
        return $result !== null ? (string) $result['label'] : null;
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

    // ── Step CRUD ────────────────────────────────────────────────

    /**
     * @param array{form_id: string, label: string, ordre?: int, actif?: int, condition?: string} $data
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
     * @param array{label?: string, ordre?: int, actif?: int, condition?: string} $data
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
        if ($fields === []) {
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

    // ── Recipient CRUD ───────────────────────────────────────────

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
}
