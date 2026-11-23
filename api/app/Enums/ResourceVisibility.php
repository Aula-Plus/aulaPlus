<?php

namespace App\Enums;

/**
 * Who sees a resource added to a group (ClickUp 1dfd3mc4bh4, answer of
 * 2026-11-22): `group` — every teacher of the group, Psicopedagogía and
 * Dirección, with the name of who added it; `own` — only the teacher who
 * added it among the teachers (Dirección and Psicopedagogía see that it
 * exists and whose it is). Only a teacher of the group can add an `own` one.
 */
enum ResourceVisibility: string
{
    case Group = 'group';
    case Own = 'own';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
