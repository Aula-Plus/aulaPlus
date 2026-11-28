<?php

namespace App\Enums;

/**
 * Each kind of request Aula+ makes to the AI, for the school's own layer of
 * instructions on top of Aula+'s fixed base (screen 24, P24-DEC-20 / PA-091,
 * P24-DEC-110 / PA-126; ClickUp 1dfd3mc4bh6).
 *
 * The layer is general — one school instruction per request type — but today
 * only judgments («Juicios») and assessments («Evaluaciones») can be filled
 * in. The others already have their place, shown empty, until their base is
 * defined (Eitán, 17/11/2026: «que ya se vea que va a estar»).
 */
enum AIRequestType: string
{
    case Judgment = 'judgment';
    case Assessment = 'assessment';
    case AnnualPlan = 'annual_plan';
    case Unit = 'unit';
    case ClassSession = 'class_session';
    case ReportSummary = 'report_summary';

    public function isConfigurable(): bool
    {
        return in_array($this, [self::Judgment, self::Assessment], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function configurableValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isConfigurable()),
        ));
    }
}
