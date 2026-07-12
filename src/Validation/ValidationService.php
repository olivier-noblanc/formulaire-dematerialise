<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Consolidated input validation service.
 *
 * Provides a single entry point for all validation and sanitization.
 * Legacy procedural functions (validate_input, validate_email, sanitize_input)
 * remain available as thin wrappers for backward compatibility.
 */
final class ValidationService
{
    /**
     * Validate a value against a named rule.
     *
     * Supported rules: uuid, email, slug, action, status, alpha_num, int, date, token.
     *
     * @param mixed  $value   Value to validate
     * @param string $rule    Rule name
     * @param array<string, mixed> $options Extra options (max_length, min, max, allowed_values)
     * @return string|int Validated value
     * @throws \InvalidArgumentException When validation fails
     */
    public function validate(mixed $value, string $rule, array $options = []): string|int
    {
        $strValue = is_string($value) ? trim($value) : (string) $value;
        $maxLength = $options['max_length'] ?? 0;

        return match ($rule) {
            'uuid' => $this->validateUuid($strValue),
            'email' => $this->validateEmailInput($strValue, $maxLength),
            'slug' => $this->validateSlug($strValue, $maxLength),
            'action' => $this->validateAction($strValue, $maxLength),
            'status' => $this->validateStatus($strValue, $options),
            'alpha_num' => $this->validateAlphaNum($strValue, $maxLength),
            'int' => $this->validateInt($strValue, $options),
            'date' => $this->validateDate($strValue),
            'token' => $this->validateToken($strValue),
            default => $maxLength > 0 ? mb_substr($strValue, 0, $maxLength) : $strValue,
        };
    }

    /**
     * Validate and normalize an email address.
     * Returns the lowercased email on success, empty string on failure.
     */
    public function validateEmail(string $email): string
    {
        $email = $email |> trim(...) |> strtolower(...);
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Sanitize a string for safe output.
     * Trims, strips slashes, and escapes HTML entities.
     */
    public function sanitize(string $input): string
    {
        $input = trim($input);
        $input = stripslashes($input);
        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }

    // ── Private rule implementations ──────────────────────────────

    private function validateUuid(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value)) {
            throw new \InvalidArgumentException('Identifiant invalide');
        }
        return strtolower($value);
    }

    private function validateEmailInput(string $value, int $maxLength): string
    {
        $value = strtolower($value);
        if ($maxLength > 0) {
            $value = mb_substr($value, 0, $maxLength);
        }
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse email invalide');
        }
        return $value;
    }

    private function validateSlug(string $value, int $maxLength): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $value)) {
            throw new \InvalidArgumentException('Slug invalide (caractères autorisés : a-z, 0-9, _, -)');
        }
        if ($maxLength > 0) {
            $value = mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    private function validateAction(string $value, int $maxLength): string
    {
        if (!preg_match('/^\w+$/', $value)) {
            throw new \InvalidArgumentException('Nom d\'action invalide');
        }
        if ($maxLength > 0) {
            $value = mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    private function validateStatus(string $value, array $options): string
    {
        $allowed = $options['allowed_values'] ?? ['en_cours', 'valide', 'refuse'];
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('Statut invalide');
        }
        return $value;
    }

    private function validateAlphaNum(string $value, int $maxLength): string
    {
        if (!preg_match('/^[\p{L}0-9\s._\-]+$/u', $value)) {
            throw new \InvalidArgumentException('Caractères non autorisés');
        }
        if ($maxLength > 0) {
            $value = mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    private function validateInt(string $value, array $options): int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);
        if ($intValue === false) {
            throw new \InvalidArgumentException('Nombre entier invalide');
        }
        if (isset($options['min']) && $intValue < $options['min']) {
            throw new \InvalidArgumentException('Valeur trop petite');
        }
        if (isset($options['max']) && $intValue > $options['max']) {
            throw new \InvalidArgumentException('Valeur trop grande');
        }
        return $intValue;
    }

    private function validateDate(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('Format de date invalide (YYYY-MM-DD attendu)');
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException('Date invalide');
        }
        return $value;
    }

    private function validateToken(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new \InvalidArgumentException('Token invalide');
        }
        return $value;
    }
}
