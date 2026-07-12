<?php

declare(strict_types=1);

namespace App\Contract;

interface ConditionInterface
{
    public function evaluate(?string $conditionJson, array $data): bool;
}
