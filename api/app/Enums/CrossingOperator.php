<?php

namespace App\Enums;

/**
 * How a crossing condition reads its datum (P30-CRU-02): in a band of an
 * indicator or screening, at a level of progress (Primaria with an official
 * scale), or below / above a number.
 */
enum CrossingOperator: string
{
    case Band = 'band';
    case Level = 'level';
    case Below = 'below';
    case Above = 'above';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
