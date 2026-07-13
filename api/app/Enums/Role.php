<?php

namespace App\Enums;

use Database\Seeders\RoleSeeder;

/**
 * The staff roles recognised by the platform. These map 1:1 to Spatie roles
 * (guard "web") created by {@see RoleSeeder}. The values are the identifiers
 * stored in the database; user-facing Spanish labels live in the frontend.
 *
 * Students are intentionally absent: they are records, not users.
 */
enum Role: string
{
    case Teacher = 'teacher';
    case Director = 'director';
    case Psychopedagogue = 'psychopedagogue';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Roles that get automatic, school-wide visibility of all groups and
     * students (as opposed to a teacher, who only sees their own).
     *
     * @return list<string>
     */
    public static function schoolWideValues(): array
    {
        return [self::Director->value, self::Psychopedagogue->value];
    }
}
