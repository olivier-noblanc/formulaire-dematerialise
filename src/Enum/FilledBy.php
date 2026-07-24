<?php

declare(strict_types=1);

namespace App\Enum;

enum FilledBy: string
{
    case Demandeur = 'demandeur';
    case Validator = 'validator';
}
