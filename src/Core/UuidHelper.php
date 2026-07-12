<?php

declare(strict_types=1);

namespace App\Core;

/**
 * UUID v4 and token generation helpers.
 */
final class UuidHelper
{
    /**
     * Generate a UUID v4 for database row identifiers (RFC 4122 compliant).
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate a 64-character hex token for validation URLs and CSRF.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
