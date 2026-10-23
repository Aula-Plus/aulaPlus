<?php

namespace App\Enums;

/**
 * Per-file visibility of a student report. Clinical reports (the default) are
 * for psychopedagogy and direction only; a file marked `teachers` (without
 * clinical content) is listed and downloadable by the student's teachers. The
 * extracted text and the AI proposals are never shown to teachers.
 */
enum ReportVisibility: string
{
    case Clinical = 'clinical';
    case Teachers = 'teachers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
