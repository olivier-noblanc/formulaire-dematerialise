<?php

declare(strict_types=1);

namespace App\Enum;

enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Date = 'date';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Textarea = 'textarea';
    case File = 'file';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
