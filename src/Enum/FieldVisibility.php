<?php

declare(strict_types=1);

namespace App\Enum;

enum FieldVisibility: string
{
    case All = 'all';
    case OwnerOnly = 'owner_only';
}
