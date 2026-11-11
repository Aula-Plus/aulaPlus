<?php

namespace App\Services;

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
 * - Open product question: switch on, no school equivalence and no number
 *   written by the teacher → nothing is shown but the level (no placeholder).
 */
class ResultScales
{
    public const PRIMARY_LEVEL_KEY = 'primary';

    /**
     * The official scale a group records its results with, or null when the
     * group records a decimal score. Only Primaria groups use a scale.
     *
     * The framework is the group's own (group_curricular_framework); a group
     * without one uses its school's active frameworks. With several candidates
     * the first framework (by id) wins, so the choice is deterministic.
     */
    public function scaleForGroup(Group $group): ?OfficialScale
    {
        $group->loadMissing('educationLevel');

        if ($group->educationLevel?->key !== self::PRIMARY_LEVEL_KEY) {
            return null;
        }

        $frameworkIds = $group->curricularFrameworks()->pluck('curricular_frameworks.id');

        if ($frameworkIds->isEmpty()) {
            $frameworkIds = $this->activeFrameworkIds($group->school_id);
        }

        return $this->primaryScaleAmong($frameworkIds);
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
