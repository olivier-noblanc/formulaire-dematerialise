<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Trait contenant les méthodes READ groupées par submission pour TokenRepository.
 *
 * Regroupe les requêtes qui retournent des données par soumission
 * (find*, count* by submission_id).
 */
trait TokenReadSubmissionTrait
{
    /**
     * Tokens actifs d'une soumission avec leur étape (vue "tokens générés").
     *
     * FIX-B (2026-09-03) : les tokens invalidés (délégation, régénération,
     * RGPD) sont exclus — leur done_at éventuel est un marqueur technique,
     * pas une validation.
     *
     * @return array<int, array{id: string, step_id: string, email: string, token: string, sent_at: string|null, step_label: string, ordre: int}>
     */
    public function findWithStepsBySubmission(string $submissionId): array
    {
        /** @var array<int, array{id: string, step_id: string, email: string, token: string, sent_at: string|null, step_label: string, ordre: int}> $result */
        $result = $this->fetchAll(
            'SELECT t.id, t.step_id, t.email, t.token, t.sent_at,
                    st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id = ? AND t.invalidated_at IS NULL
             ORDER BY st.ordre',
            [$submissionId]
        );
        return $result;
    }

    /**
     * Vue détaillée des tokens d'une soumission (page détail : diagramme de
     * workflow, historique des validations, formulaire de délégation, actions
     * admin).
     *
     * FIX-B (2026-09-03) : les tokens invalidés sont exclus — un token
     * délégué/régénéré porte done_at + invalidated_at (faux "done") et un
     * token RGPD porte invalidated_at seul (faux "pending") : aucun des deux
     * ne doit être affiché comme validé ou en attente.
     *
     * @return array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, ordre: int}>
     */
    public function findDetailedWithStepsBySubmission(string $submissionId): array
    {
        /** @var array<int, array{id: string, submission_id: string, step_id: string, email: string, token: string, sent_at: string|null, done_at: string|null, relance_at: string|null, expires_at: string|null, relance_count: int, step_label: string, ordre: int}> $result */
        $result = $this->fetchAll(
            'SELECT t.id, t.submission_id, t.step_id, t.email, t.token, t.sent_at, t.done_at, t.relance_at, t.expires_at, t.relance_count, st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id = ? AND t.invalidated_at IS NULL
             ORDER BY st.ordre ASC, t.sent_at ASC',
            [$submissionId]
        );
        return $result;
    }

    /**
     * Tokens groupés par soumission (dashboard, mes soumissions).
     *
     * FIX-B (2026-09-03) : les tokens invalidés sont exclus — leur done_at
     * éventuel (délégation/régénération) ne doit pas afficher de "✓" ni
     * marquer la soumission comme terminée, et leur invalidated_at seul
     * (RGPD) ne doit pas les compter comme en attente.
     *
     * @param array<int, string> $submissionIds
     * @return array<string, list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}>>
     */
    public function findBySubmissionIds(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        /** @var list<array{submission_id: string, id: string, token: string, relance_count: int, expires_at: string|null, email: string, done_at: string|null, sent_at: string|null, step_id: string, label: string, step_label: string, ordre: int}> $rows */
        $rows = $this->fetchAll(
            "SELECT t.submission_id, t.id, t.token, t.relance_count, t.expires_at,
                    t.email, t.done_at, t.sent_at, t.step_id,
                    st.label, st.label as step_label, st.ordre
             FROM tokens t
             JOIN steps st ON st.id = t.step_id
             WHERE t.submission_id IN ($placeholders) AND t.invalidated_at IS NULL
             ORDER BY t.submission_id, st.ordre ASC, st.label ASC",
            $submissionIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['submission_id']][] = $row;
        }
        return $result;
    }

    /**
     * Étapes actives d'une soumission avec l'état de leurs tokens agrégé.
     *
     * Les tokens invalidés (délégation, régénération, RGPD) sont exclus de la
     * jointure — ils ne doivent ni marquer l'étape comme "done" (leur done_at
     * d'invalidation ne compte pas), ni fausser les comptes.
     *
     * `dones` agrège COALESCE(done_at, '') : chaque token actif contribue une
     * entrée — vide pour un token en attente — afin que le consommateur
     * (MyValidationsRenderer) puisse distinguer "tous validés" de "partiel".
     * NULLIF restaure NULL quand aucune validation n'existe (0 token ou
     * uniquement des tokens en attente).
     *
     * @param array<int, string> $submissionIds
     * @return array<string, list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}>>
     */
    public function findStepsBySubmissionIds(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        /** @var list<array{submission_id: string, id: string, label: string, ordre: int, dones: string|null}> $rows */
        $rows = $this->fetchAll(
             "SELECT s.id as submission_id, st.id, st.label, st.ordre,
                    NULLIF(GROUP_CONCAT(COALESCE(t2.done_at, ''), '|'), '') as dones
             FROM submissions s
             JOIN steps st ON st.form_id = s.form_id AND st.actif = 1
             LEFT JOIN tokens t2 ON t2.step_id = st.id AND t2.submission_id = s.id AND t2.invalidated_at IS NULL
             WHERE s.id IN ($placeholders)
             GROUP BY s.id, st.id
             ORDER BY s.id, st.ordre",
            $submissionIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['submission_id']][] = $row;
        }
        return $result;
    }

    /**
     * @param array<int, string> $submissionIds
     * @return array<string, int>
     */
    public function countPendingBySubmissionIds(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        /** @var list<array{submission_id: string, cnt: int|string}> $rows */
        $rows = $this->fetchAll(
            "SELECT submission_id, COUNT(*) as cnt FROM tokens WHERE submission_id IN ($placeholders) AND done_at IS NULL AND invalidated_at IS NULL GROUP BY submission_id",
            $submissionIds
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['submission_id']] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * @return array<int, array{step_id: string, email: string, sent_at: string|null, done_at: string|null, expires_at: string|null}>
     */
    public function findForExport(string $submissionId): array
    {
        /** @var array<int, array{step_id: string, email: string, sent_at: string|null, done_at: string|null, expires_at: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT step_id, email, sent_at, done_at, expires_at
             FROM tokens WHERE submission_id = ? ORDER BY sent_at',
            [$submissionId]
        );
        return $result;
    }

    /**
     * @return list<array{step_id: string, done_at: string|null}>
     */
    public function findStepIdsAndDonesBySubmission(string $submissionId): array
    {
        /** @var list<array{step_id: string, done_at: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT step_id, done_at FROM tokens WHERE submission_id = ?',
            [$submissionId]
        );
        return $result;
    }
}
