<?php

namespace App\Services;

use App\Enums\GradesVisibility;
use App\Enums\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Decides which of a student's grades a viewer may see. Psychopedagogy and
 * direction see everything. A teacher always sees live grades of the subjects
 * they teach to this student's groups; for the other subjects the school's
 * setting applies: hidden (default), live, or frozen at the last periodic
 * cutoff. Enforced here on the server so it holds on every endpoint that
 * returns scores, not just in the UI.
 */
class StudentGradeAccess
{
    /**
     * Constrain a query over `assessment_results` that already joins
     * `assessments` to what $user may see for $student.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, User $user, Student $student): Builder
    {
        if ($this->seesEverything($user)) {
            return $query;
        }

        $school = School::query()->findOrFail($student->school_id);
        $own = $this->teachingSubjectIds($user, $student);

        return $query->where(function (Builder $q) use ($school, $own) {
            $q->whereIn('assessments.subject_id', $own);

            if ($school->grades_visibility === GradesVisibility::AllLive) {
                $q->orWhereRaw('1 = 1');
            } elseif ($school->grades_visibility === GradesVisibility::AllPeriodic
                && ($cutoff = $this->lastCutoff($school)) !== null) {
                $q->orWhere('assessment_results.created_at', '<=', $cutoff->copy()->endOfDay());
            }
        });
    }

    /**
     * What the UI needs to explain the restriction (never any grade data).
     *
     * @return array{mode: string, restricted: bool, own_subject_ids: list<int>, cutoff_at: string|null}
     */
    public function describe(User $user, Student $student): array
    {
        $school = School::query()->findOrFail($student->school_id);

        if ($this->seesEverything($user)) {
            return ['mode' => 'all_live', 'restricted' => false, 'own_subject_ids' => [], 'cutoff_at' => null];
        }

        $mode = $school->grades_visibility;

        return [
            'mode' => $mode->value,
            'restricted' => $mode !== GradesVisibility::AllLive,
            'own_subject_ids' => $this->teachingSubjectIds($user, $student),
            'cutoff_at' => $mode === GradesVisibility::AllPeriodic
                ? $this->lastCutoff($school)?->toDateString()
                : null,
        ];
    }

    /**
     * The most recent cutoff date on or before today: anchor + k × months.
     */
    public function lastCutoff(School $school, ?Carbon $today = null): ?Carbon
    {
        $anchor = $school->grades_cutoff_anchor;
        $months = $school->grades_cutoff_months;
        $today ??= Carbon::today();

        if ($anchor === null || ! $months || $anchor->gt($today)) {
            return null;
        }

        $cutoff = $anchor->copy()->startOfDay();
        while ($cutoff->copy()->addMonthsNoOverflow($months)->lte($today)) {
            $cutoff = $cutoff->addMonthsNoOverflow($months);
        }

        return $cutoff;
    }

    protected function seesEverything(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }

    /**
     * @return list<int>
     */
    protected function teachingSubjectIds(User $user, Student $student): array
    {
        return $user->groups()
            ->whereIn('groups.id', $student->groups()->pluck('groups.id'))
            ->whereNotNull('group_teacher.subject_id')
            ->pluck('group_teacher.subject_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
