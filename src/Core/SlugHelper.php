<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Slug and field name generation helpers.
 */
final class SlugHelper
{
    /**
     * Generate a field_name from a label.
     * Ex: "Date de prise de poste" → "date_de_prise_de_poste"
     */
    public static function generateFieldName(string $label): string
    {
        $name = mb_strtolower($label, 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
            if ($transliterated !== false) {
                $name = $transliterated;
            }
        }
        $name = str_replace(
            ['à','â','ä','é','è','ê','ë','ï','î','ô','ö','ù','û','ü','ç','œ','æ','ÿ'],
            ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','c','oe','ae','y'],
            $name
        );
        $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        $name = preg_replace('/_+/', '_', $name) ?? $name;
        return $name ?? 'champ';
    }

    /**
     * Generate a unique slug from a label.
     */
    public static function generateSlug(string $label, ?string $excludeFormId = null): string
    {
        $base = self::generateFieldName($label);
        if ($base === '' || $base === '0') {
            $base = 'formulaire';
        }

        $pdo = \App\Core\App::db()->getPdo();
        $slug = $base;
        $suffix = 2;

        $maxAttempts = 100;
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            $sql = 'SELECT COUNT(*) FROM forms WHERE slug = ?';
            $params = [$slug];
            if ($excludeFormId !== null) {
                $sql .= ' AND id !== ?';
                $params[] = $excludeFormId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '_' . $suffix;
            $suffix++;
            $attempts++;
        }
        throw new \RuntimeException('Impossible de générer un slug unique après ' . $maxAttempts . ' tentatives');
    }

    /**
     * Parse options input (one per line) into JSON array.
     */
    public static function parseOptionsInput(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || $input === '0') {
            return null;
        }

        $decoded = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $input;
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $input)), fn($l) => $l !== ''));
        if ($lines === []) {
            return null;
        }

        $result = json_encode($lines, JSON_UNESCAPED_UNICODE);
        return $result === false ? null : $result;
    }

    /**
     * Retrieve a form by UUID.
     * @return array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|null
     */
    public static function getFormByUuid(string $uuid): ?array
    {
        $pdo = \App\Core\App::db()->getPdo();
        $stmt = $pdo->prepare('SELECT id, slug, label, description, actif, created_at, deadline_field FROM forms WHERE id = ?');
        $stmt->execute([$uuid]);
        /** @var array{id: string, slug: string, label: string, description: string|null, actif: int, created_at: string, deadline_field: string}|false */
        $form = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $form !== false ? $form : null;
    }
}
