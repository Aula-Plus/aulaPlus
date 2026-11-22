<?php

namespace App\Enums;

/**
 * What an entry of psychopedagogy's agenda is (screen 10, P10-DEC-03): an
 * individual session with a student leaves a note in the student's file; a
 * meeting with more than one person is of one of the school's sheet types and
 * leaves a sheet («planilla»).
 */
enum PsychopedagogyMeetingKind: string
{
    case IndividualSession = 'individual_session';
    case Meeting = 'meeting';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
