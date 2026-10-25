<?php

namespace App\Actions\Screening;

use App\Models\ScreeningTestApplication;
use App\Models\User;

/**
 * «Recalcular con el nivel actual» (ClickUp 86e3t4qjh, rule c): the explicit
 * step of a person that replaces an application's snapshot with the values in
 * force now — the group's level, the population that level corresponds to and
 * the screening's population. It is the only way an applied screening moves in
 * the impact view; who did it and when are kept on the application (and the
 * before/after values in the audit log).
 */
class RecalculateScreeningTestApplication
{
    public function __invoke(ScreeningTestApplication $application, User $by): ScreeningTestApplication
    {
        $level = $application->group->educationLevel;

        $application->update([
            'education_level_id' => $level?->id,
            'screening_population' => $level?->screening_population,
            'population_levels' => $application->type->levels ?? [],
            'recalculated_by_id' => $by->id,
            'recalculated_at' => now(),
        ]);

        return $application;
    }
}
