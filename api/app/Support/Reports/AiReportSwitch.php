<?php

namespace App\Support\Reports;

use App\Models\ReportDocument;
use App\Models\School;
use App\Support\AiProvider;

/**
 * Whether the AI may read a student report (ClickUp 86e3jujgx). Three
 * independent switches, all of which must be on — if any is off there is no AI:
 *
 * 1. the global brake (`services.ai_report.enabled`, ours, for every school);
 * 2. the school's own switch (direction, `ai_report_interpretation_enabled`);
 * 3. the per-report choice (`use_ai`, psychopedagogy can skip it for one report).
 *
 * The provider also has to be configured.
 */
final class AiReportSwitch
{
    public static function globallyEnabled(): bool
    {
        return (bool) config('services.ai_report.enabled') && AiProvider::isConfigured();
    }

    public static function availableFor(School $school): bool
    {
        return self::globallyEnabled() && $school->ai_report_interpretation_enabled;
    }

    public static function allowedFor(ReportDocument $report, School $school): bool
    {
        return $report->use_ai && self::availableFor($school);
    }
}
