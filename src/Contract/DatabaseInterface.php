<?php

declare(strict_types=1);

namespace App\Contract;

use PDO;

interface DatabaseInterface
{
    public function getPdo(): PDO;
    public function release(): void;
}
