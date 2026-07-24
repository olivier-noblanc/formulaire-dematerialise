<?php

declare(strict_types=1);

namespace App\Enum;

enum ValidationAction: string
{
    case Valider = 'valider';
    case Refuser = 'refuser';
}
