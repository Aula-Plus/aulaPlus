<?php

namespace App\Services;

use App\Enums\GradesVisibility;
use App\Enums\Role;
use App\Models\Group;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decides which grades a viewer may see. Psychopedagogy and direction see
 * everything. A teacher always sees live grades of the subjects they teach to
 * this student's groups; for the other subjects the school's setting applies:
 * hidden (default), live, or only those loaded before the last periodic
 * cutoff. Enforced here on the server so it holds on every endpoint that
 * returns scores, not just in the UI.
 *
 * The rule is by subject, not by (group, subject): a teacher of Math in any
 * of the student's groups sees all of that student's Math grades.
 *
 * Callers that run several grade queries in one request should resolve the
 * rule once ({@see self::forStudent()}) and apply it to each query.
 */
class StudentGradeAccess
{
    /**
     * The rule for $user looking at $student.
     */
    public function forStudent(User $user, Student $student): GradeAccessRule
    {
        if ($this->seesEverything($user)) {
            return GradeAccessRule::unrestricted();
        }

        return $this->ruleFor(
            School::query()->findOrFail($student->school_id),
            fn () => $user->groups()
                ->whereIn('groups.id', $student->groups()->select('groups.id'))
                ->pluck('group_teacher.subject_id'),
        );
    }

    /**
     * The rule for $user looking at a group's aggregate (e.g. the group
     * performance line). The same setting applies: a teacher restricted to
     * their own subject only gets the averages of assessments of the subjects
     * they teach in this group — with tiny groups an average is close to an
     * individual grade.
     */
    public function forGroup(User $user, Group $group): GradeAccessRule
    {
        if ($this->seesEverything($user)) {
            return GradeAccessRule::unrestricted();
        }

        return $this->ruleFor(
            School::query()->findOrFail($group->school_id),
            fn () => $user->groups()
                ->where('groups.id', $group->id)
                ->pluck('group_teacher.subject_id'),
        );
    }

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
        return $this->forStudent($user, $student)->apply($query);
    }

    /**
     * What the UI needs to explain the restriction (never any grade data).
     *
     * @return array{mode: string, restricted: bool, own_subject_ids: list<int>, cutoff_at: string|null}
     */
    public function describe(User $user, Student $student): array
    {
        return $this->forStudent($user, $student)->describe();
    }

    /**
     * The most recent cutoff on or before today (school time): anchor + k ×
     * months, or null when periodic mode isn't configured or the anchor is in
     * the future.
     */
    public function lastCutoff(School $school, ?CarbonInterface $today = null): ?CarbonImmutable
    {
        $anchor = $school->grades_cutoff_anchor;
        $months = $school->grades_cutoff_months;

        if ($anchor === null || ! $months) {
            return null;
        }

        // Pure calendar dates, so no timezone can shift them.
        $anchor = CarbonImmutable::parse($anchor->format('Y-m-d'));
        $today = CarbonImmutable::parse(($today ?? SchoolTime::today())->format('Y-m-d'));

        if ($anchor->gt($today)) {
            return null;
        }

        // k is computed arithmetically (no loop over the elapsed periods), and
        // each cutoff from the anchor (anchor + k × months), never by stepping
        // from the previous cutoff: stepping would drift once a month clamps
        // (Jan 31 → Feb 28 → Mar 28 instead of Mar 31). The calendar-month
        // difference can overshoot by one period only when the candidate lands
        // later in today's month, so a single correction is enough.
        $elapsedMonths = ($today->year - $anchor->year) * 12 + ($today->month - $anchor->month);
        $k = intdiv($elapsedMonths, $months);

        if ($anchor->addMonthsNoOverflow($k * $months)->gt($today)) {
            $k--;
        }

        return $anchor->addMonthsNoOverflow($k * $months);
    }

    /**
     * @param  callable(): Collection<int, mixed>  $ownSubjectIds
     */
    protected function ruleFor(School $school, callable $ownSubjectIds): GradeAccessRule
    {
        $mode = $school->grades_visibility;

        if ($mode === GradesVisibility::AllLive) {
            return new GradeAccessRule($mode, false);
        }

        return new GradeAccessRule(
            $mode,
            true,
            // group_teacher.subject_id is NOT NULL: every assignment has a subject.
            $ownSubjectIds()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            $mode === GradesVisibility::AllPeriodic ? $this->lastCutoff($school) : null,
        );
    }

    protected function seesEverything(User $user): bool
    {
        return $user->hasAnyRole(Role::schoolWideValues());
    }
}
