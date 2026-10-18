<?php

namespace App\Enums;

/**
 * The kind of content a creation journey is about. Mirrors AIProposalType
 * (programs, units, classes, assessments). Projects are deliberately absent
 * until that entity exists (CLAUDE.md "Out of scope for now").
 */
enum JourneyContentType: string
{
    case AnnualPlan = 'annual_plan';
    case Unit = 'unit';
    case ClassSession = 'class_session';
    case Assessment = 'assessment';

    /**
     * The content types a browser create form exists for today. Only the
     * manual assessment form; the rest arrive with screens 14–17.
     *
     * @return list<string>
     */
    public static function clientReportableValues(): array
    {
        return [self::Assessment->value];
    }
}
