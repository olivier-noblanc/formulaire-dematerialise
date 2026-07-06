<?php
declare(strict_types=1);

namespace App\Contract;

interface SettingsInterface
{
    public function get(string $key, string $default = ''): string;
    public function set(string $key, string $value): void;
    public function encrypt(string $value): string;
    public function decrypt(string $value): string;
}
