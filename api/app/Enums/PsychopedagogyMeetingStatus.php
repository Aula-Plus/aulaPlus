<?php

namespace App\Enums;

/** «Programada» / «Realizada» (P10-AGE-02). */
enum PsychopedagogyMeetingStatus: string
{
    case Scheduled = 'scheduled';
    case Done = 'done';
}
