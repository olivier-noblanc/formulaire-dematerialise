<?php

declare(strict_types=1);

namespace App\Core;

use App\Repository\FormRepository;

/**
 * Slug and field name generation helpers.
 *
 * Historiquement ces helpers accédaient directement à PDO via App::db()->getPdo().
 * Depuis la migration vers le pattern Repository, ils délèguent à FormRepository
 * (résolu via App::getInstance()) pour tout accès DB.
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
        return $name !== '' && $name !== '0' ? $name : 'champ';
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

        $formRepo = self::getFormRepository();
        $slug = $base;
        $suffix = 2;

        $maxAttempts = 100;
        $attempts = 0;
        while ($attempts < $maxAttempts) {
            if ($formRepo->countBySlug($slug, $excludeFormId) === 0) {
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

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $input)), fn(string $l): bool => $l !== ''));
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
        return self::getFormRepository()->findById($uuid);
    }

    /**
     * Résout le FormRepository via App::getInstance(). Fallback : instancie
     * un FormRepository directement (pour les contextes où App n'a pas le
     * repo enregistré, ex. tests unitaires isolés).
     */
    private static function getFormRepository(): FormRepository
    {
        $app = \App\Core\App::getInstance();
        if ($app->has(FormRepository::class)) {
            return $app->get(FormRepository::class);
        }
        return new FormRepository($app->get(Database::class));
    }
}
