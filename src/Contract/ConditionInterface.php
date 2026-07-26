<?php

declare(strict_types=1);

namespace App\Contract;

interface ConditionInterface
{
    /** @param array<string, mixed> $data */
    public function evaluate(?string $conditionJson, array $data): bool;
}
