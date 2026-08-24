<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * @internal Trait utilisé par FormRepository pour limiter la taille du fichier principal.
 *
 * @method bool execute(string $sql, array<int, mixed> $params = [])
 */
trait FormOwnersTrait
{
    public function findOwnerEmailById(string $ownerId): ?string
    {
        $result = $this->fetchOne('SELECT email FROM form_owners WHERE id = ?', [$ownerId]);
        return $result !== null ? (string) $result['email'] : null;
    }

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
     * @return list<array{id: string, label: string, slug: string, actif: int, description: string|null}>
     */
    public function findOwnedFormsByEmail(string $email): array
    {
        /** @var list<array{id: string, label: string, slug: string, actif: int, description: string|null}> $result */
        $result = $this->fetchAll(
            'SELECT f.id, f.label, f.slug, f.actif, f.description
            FROM forms f
            JOIN form_owners fo ON fo.form_id = f.id
            WHERE LOWER(fo.email) = LOWER(?)
            ORDER BY f.label',
            [$email]
        );
        return $result;
    }
}
