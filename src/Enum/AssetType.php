<?php

declare(strict_types=1);

namespace App\Enum;

enum AssetType: string
{
    case Css = 'css';
    case Js = 'js';
}
