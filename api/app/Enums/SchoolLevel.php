<?php

namespace App\Enums;

/**
 * The levels a screening's population can cover (screen 08 "Población": a
 * qué niveles se aplica — Primaria, Ciclo Básico, Bachillerato).
 */
enum SchoolLevel: string
{
    case Primary = 'primary';
    case LowerSecondary = 'lower_secondary';
    case UpperSecondary = 'upper_secondary';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
