<?php

namespace App\Enums;

/**
 * The colour of a screening band (screen 08: "cada franja tiene nombre, color,
 * hasta qué puntaje llega y qué significa"). The school picks one per band; the
 * identifiers are English by repo convention and the Spanish labels live in
 * the frontend. The band's NAME and meaning are institutional text stored on
 * the design, never on this enum.
 */
enum ScreeningColor: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Green = 'green';
    case Blue = 'blue';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $color): string => $color->value, self::cases());
    }
}
