<?php

namespace App\Enums;

/**
 * A level's standing before ANEP (screens 23 and 29, P29-DEC-01): «Habilitado»
 * follows the national program; «Autorizado» has its own. Not a preference:
 * Aula+ loads it from the official documentation. Spanish labels in the SPA.
 */
enum LevelRegime: string
{
    case Enabled = 'enabled';
    case Authorized = 'authorized';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $regime): string => $regime->value, self::cases());
    }
}
