<?php

namespace App\Enums;

/**
 * Who an alert reaches first ("a quién le llega primero"), configurable per
 * alert type by the school. Snapshotted onto each alert when it is generated
 * (`alerts.recipients`) so a later settings change never hides or reveals an
 * alert retroactively.
 *
 * - Teacher: the teacher who teaches the alert's subject in one of the
 *   student's groups (or, for an alert with no subject, any teacher of the
 *   student) — never every teacher in the school.
 * - Psychopedagogue / Director: everyone holding that role in the school.
 */
enum AlertRecipient: string
{
    case Teacher = 'teacher';
    case Psychopedagogue = 'psychopedagogue';
    case Director = 'director';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $recipient): string => $recipient->value, self::cases());
    }
}
