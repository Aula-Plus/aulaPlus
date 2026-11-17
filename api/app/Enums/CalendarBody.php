<?php

namespace App\Enums;

/**
 * The body whose calendar a curricular program follows (screen 26 «Año y
 * calendario», ClickUp 1dfd3mc4bh7, Eitán 17/11, as in PA-110): the periods
 * are set once per body — ANEP Bachillerato, ANEP Ciclo Básico y Primaria,
 * Cambridge and IB — not once per catalog program. Stored as data on
 * `curricular_frameworks.calendar_body` (null = the program is its own row).
 * Spanish labels live in the frontend.
 */
enum CalendarBody: string
{
    case AnepBasic = 'anep_basic';
    case AnepUpperSecondary = 'anep_upper_secondary';
    case Cambridge = 'cambridge';
    case Ib = 'ib';

    /**
     * Cambridge and IB can be «Separar por programa», for the school that runs
     * one of their programs on a different calendar (e.g. one by terms and
     * another by semesters). Each ANEP body is already a single program.
     */
    public function canSplitByProgram(): bool
    {
        return in_array($this, [self::Cambridge, self::Ib], true);
    }
}
