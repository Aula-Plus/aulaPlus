<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\OfficialScale;
use App\Models\School;
use App\Models\SchoolScaleEquivalence;
use Illuminate\Support\Collection;

/**
 * Single source of truth for how a result is recorded and how its number is
 * shown (ClickUp 1dfd3mc4bgg; living document screen 24, P24-EVA-03 /
 * P24-ESC-02 / P24-DEC-03):
 *
 * - A Primaria group whose curricular framework has an official scale for
 *   Primaria records each result as a LEVEL of that scale. Every other group
 *   keeps the decimal score, as before.
 * - The number 1..10 next to a level is shown only when the school turned on
 *   «Mostrar la calificación numérica junto al nivel de avance». It is the
 *   school's own equivalence when the school configured one, otherwise the
 *   number the teacher wrote with the level. Aula+ never derives it.
 * - Switch on, no school equivalence and no number written by the teacher →
 *   the level is shown with a discreet «sin nota» (PO answer of 12/11, done
 *   in the frontend; `numeric_grade` stays null here).
 * - When a group has two valid Primaria scales, the teacher chooses one per
 *   assessment before the first result, and it is kept with the assessment
 *   (`assessments.official_scale_id`), so the recorded results stay tied to
 *   it (PO answer of 12/11).
 */
class ResultScales
{
    public const PRIMARY_LEVEL_KEY = 'primary';

    /**
     * Every official Primaria scale a group may record with: the scales of
     * its own frameworks or, when it has none, of its school's active ones.
     * Empty for any group that is not Primaria.
     *
     * @return Collection<int, OfficialScale>
     */
    public function primaryScalesForGroup(Group $group): Collection
    {
        $group->loadMissing('educationLevel');

        if ($group->educationLevel?->key !== self::PRIMARY_LEVEL_KEY) {
            return collect();
        }

        $frameworkIds = $group->curricularFrameworks()->pluck('curricular_frameworks.id');

        if ($frameworkIds->isEmpty()) {
            $frameworkIds = $this->activeFrameworkIds($group->school_id);
        }

        if ($frameworkIds->isEmpty()) {
            return collect();
        }

        return OfficialScale::query()
            ->whereIn('curricular_framework_id', $frameworkIds)
            ->whereHas('educationLevel', fn ($q) => $q->where('key', self::PRIMARY_LEVEL_KEY))
            ->with(['levels', 'curricularFramework', 'educationLevel'])
            ->orderBy('curricular_framework_id')
            ->get();
    }

    /**
     * How an assessment records its results:
     *
     * - `scale`: the scale kept with the assessment once chosen; otherwise
     *   the group's only valid Primaria scale; null when the assessment
     *   records a decimal score, or while a choice is still pending.
     * - `choices`: the scales the teacher must choose among first (two or
     *   more valid ones and none kept yet); empty otherwise.
     *
     * @return array{scale: OfficialScale|null, choices: Collection<int, OfficialScale>}
     */
    public function resolveForAssessment(Assessment $assessment): array
    {
        if ($assessment->official_scale_id !== null) {
            $scale = OfficialScale::query()
                ->with(['levels', 'curricularFramework', 'educationLevel'])
                ->find($assessment->official_scale_id);

            return ['scale' => $scale, 'choices' => collect()];
        }

        $candidates = $assessment->group ? $this->primaryScalesForGroup($assessment->group) : collect();

        return $candidates->count() > 1
            ? ['scale' => null, 'choices' => $candidates]
            : ['scale' => $candidates->first(), 'choices' => collect()];
    }

    /**
     * The school's Primaria scale: the one its equivalence is configured for.
     */
    public function primaryScaleForSchool(School $school): ?OfficialScale
    {
        return $this->primaryScaleAmong($this->activeFrameworkIds($school->id));
    }

    /**
     * The scales of the frameworks the school uses (the «Escalas oficiales»
     * tab), with their levels in order.
     *
     * @return Collection<int, OfficialScale>
     */
    public function scalesForSchool(School $school): Collection
    {
        return OfficialScale::query()
            ->whereIn('curricular_framework_id', $this->activeFrameworkIds($school->id))
            ->with(['levels', 'curricularFramework', 'educationLevel'])
            ->get()
            ->sortBy(fn (OfficialScale $scale) => [
                $scale->curricularFramework->name,
                $scale->educationLevel->position ?? PHP_INT_MAX,
            ])
            ->values();
    }

    /**
     * The school's own equivalence, level id → number 1..10 (empty when the
     * school did not configure one).
     *
     * @return array<int, int>
     */
    public function equivalences(School $school): array
    {
        return SchoolScaleEquivalence::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->id)
            ->pluck('numeric_grade', 'official_scale_level_id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Whether the school configured its equivalence for this scale.
     *
     * @param  array<int, int>  $equivalences
     */
    public function hasEquivalenceFor(OfficialScale $scale, array $equivalences): bool
    {
        return $scale->levels->contains(fn ($level) => isset($equivalences[$level->id]));
    }

    /**
     * Whether a teacher writes the number next to the level when loading a
     * result for this scale: only when the number is shown at all and the
     * school has no equivalence of its own.
     *
     * @param  array<int, int>  $equivalences
     */
    public function teacherWritesNumber(School $school, OfficialScale $scale, array $equivalences): bool
    {
        return $school->show_primary_numeric_grade && ! $this->hasEquivalenceFor($scale, $equivalences);
    }

    /**
     * The number shown next to a level result, and where it comes from.
     *
     * @param  array<int, int>  $equivalences
     * @return array{value: int|null, source: 'school'|'teacher'|null}
     */
    public function numericGradeFor(AssessmentResult $result, School $school, array $equivalences): array
    {
        if ($result->official_scale_level_id === null || ! $school->show_primary_numeric_grade) {
            return ['value' => null, 'source' => null];
        }

        if (isset($equivalences[$result->official_scale_level_id])) {
            return ['value' => $equivalences[$result->official_scale_level_id], 'source' => 'school'];
        }

        if ($result->numeric_grade !== null) {
            return ['value' => $result->numeric_grade, 'source' => 'teacher'];
        }

        // Open question (ClickUp 1dfd3mc4bgg): neutral — only the level.
        return ['value' => null, 'source' => null];
    }

    /**
     * @param  Collection<int, int>  $frameworkIds
     */
    protected function primaryScaleAmong(Collection $frameworkIds): ?OfficialScale
    {
        if ($frameworkIds->isEmpty()) {
            return null;
        }

        return OfficialScale::query()
            ->whereIn('curricular_framework_id', $frameworkIds)
            ->whereHas('educationLevel', fn ($q) => $q->where('key', self::PRIMARY_LEVEL_KEY))
            ->with('levels')
            ->orderBy('curricular_framework_id')
            ->first();
    }

    /**
     * @return Collection<int, int>
     */
    protected function activeFrameworkIds(int $schoolId): Collection
    {
        return School::query()->findOrFail($schoolId)
            ->curricularFrameworks()
            ->wherePivot('active', true)
            ->pluck('curricular_frameworks.id');
    }
}
