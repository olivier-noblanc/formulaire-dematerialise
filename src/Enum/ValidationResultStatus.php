<?php

declare(strict_types=1);

namespace App\Enum;

enum ValidationResultStatus: string
{
    case PENDING = 'pending';
    case OK = 'ok';
    case INVALID = 'invalid';
    case EXPIRED = 'expired';
    case ALREADY_DONE = 'already_done';
    case CLOSED = 'closed';
}
