<?php

namespace App\Enums;

/**
 * What kind of material it is (screen 19, «Tipo»). Spanish labels live in the
 * SPA (`materialTypeLabels` in web/src/types.ts).
 */
enum MaterialType: string
{
    case Guide = 'guide';
    case Worksheet = 'worksheet';
    case Activity = 'activity';
    case Reading = 'reading';
    case Presentation = 'presentation';
    case Assessment = 'assessment';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
