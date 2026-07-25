<?php

declare(strict_types=1);

namespace App\Enum;

enum UrgencyLevel: string
{
    case Overdue = 'overdue';
    case Critical = 'critical';
}
