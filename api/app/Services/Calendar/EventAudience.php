<?php

namespace App\Services\Calendar;

use App\Enums\Role;
use App\Models\CalendarEvent;
use App\Models\CalendarEventAudience;
use App\Models\CurricularFramework;
use App\Models\CurricularOffering;
use App\Models\EducationLevel;
use App\Models\Group;
use App\Models\School;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who an institutional event concerns (screen 21, P21-DEC-05/108; ClickUp
 * 1dfd3mc55zp, Eitán 17/11/2026): the whole school (no targets), or any
 * combination of levels, generations (grades) and the groups of a program
 * within a generation.
 *
 * «Each person sees the ones of their groups and students»:
 *
 * - A teacher: the groups where they teach a subject (group_teacher) of the
 *   event's school year (the calendar year of its school-local date). Their
 *   students are the students of those groups, so the groups decide.
 * - Psychopedagogy and Direction: school-wide roles — every group and student
 *   is theirs — so every institutional event concerns them.
 *
 * This only decides what a person's own calendar shows by default. Every
 * institutional event stays readable by any staff member of the school
 * (CalendarEventPolicy::view) in the full calendar («Ver todo el calendario
 * del colegio»): it is a UX filter, not a security boundary.
 */
class EventAudience
{
    /**
     * What Direction can pick: the levels with their grades (global data) and
     * the programs the school offers besides its mandatory regime (ANEP for
     * every group: «ANEP in 3° de Primaria» is the generation itself).
     *
     * @return array{levels: list<array<string, mixed>>, frameworks: list<array<string, mixed>>}
     */
    public function options(School $school): array
    {
        return [
            'levels' => EducationLevel::query()->with('grades')->orderBy('position')->get()
                ->map(fn (EducationLevel $level): array => [
                    'id' => $level->id,
                    'key' => $level->key,
                    'label' => $level->label,
                    'grades' => $level->grades->map(fn ($grade): array => [
                        'id' => $grade->id,
                        'key' => $grade->key,
                        'label' => $grade->label,
                    ])->all(),
                ])->all(),
            'frameworks' => $this->pickableFrameworks($school)
                ->map(fn (CurricularFramework $framework): array => [
                    'id' => $framework->id,
                    'name' => $framework->name,
                    'education_levels' => $framework->education_levels,
                ])->values()->all(),
        ];
    }

    /**
     * The school's active, non-mandatory programs.
     *
     * @return Collection<int, CurricularFramework>
     */
    public function pickableFrameworks(School $school): Collection
    {
        return $school->curricularFrameworks()
            ->wherePivot('active', true)
            ->wherePivot('mandatory', false)
            ->orderBy('curricular_frameworks.name')
            ->get();
    }

    /**
     * Replace an event's targets. An empty list = the whole school.
     *
     * @param  list<array{level_id?: int|null, grade_id?: int|null, framework_id?: int|null}>  $targets
     */
    public function replace(CalendarEvent $event, array $targets): void
    {
        DB::transaction(function () use ($event, $targets): void {
            $event->audiences()->delete();

            foreach ($targets as $target) {
                // Always the event's own school, whatever tenant is set.
                $event->audiences()->make([
                    'education_level_id' => $target['level_id'] ?? null,
                    'school_grade_id' => $target['grade_id'] ?? null,
                    'curricular_framework_id' => $target['framework_id'] ?? null,
                ])->forceFill(['school_id' => $event->school_id])->save();
            }
        });

        $event->unsetRelation('audiences');
    }

    /**
     * The event's targets as the SPA labels them (labels are the catalog's
     * own data; the SPA writes «Todo el colegio» for an empty list).
     *
     * @return list<array<string, mixed>>
     */
    public function present(CalendarEvent $event): array
    {
        $event->loadMissing('audiences.educationLevel', 'audiences.schoolGrade', 'audiences.curricularFramework');

        return $event->audiences
            ->sortBy('id')
            ->map(fn (CalendarEventAudience $audience): array => [
                'level' => $audience->educationLevel
                    ? ['id' => $audience->educationLevel->id, 'key' => $audience->educationLevel->key, 'label' => $audience->educationLevel->label]
                    : null,
                'grade' => $audience->schoolGrade
                    ? ['id' => $audience->schoolGrade->id, 'key' => $audience->schoolGrade->key, 'label' => $audience->schoolGrade->label]
                    : null,
                'framework' => $audience->curricularFramework
                    ? ['id' => $audience->curricularFramework->id, 'name' => $audience->curricularFramework->name]
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The groups that make an event «theirs» for a person, or null when every
     * event is (school-wide roles). Each group: its school year, level, grade
     * and the programs of its «Oferta curricular» (any subject).
     *
     * @return Collection<int, array{year: int, level_id: int|null, grade_id: int|null, framework_ids: list<int>}>|null
     */
    public function groupsOf(User $user): ?Collection
    {
        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return null;
        }

        $groups = Group::query()
            ->whereHas('teachers', fn ($query) => $query->where('users.id', $user->id))
            ->get(['id', 'school_year', 'education_level_id', 'school_grade_id']);

        $frameworks = CurricularOffering::query()
            ->whereIn('group_id', $groups->pluck('id'))
            ->get(['group_id', 'curricular_framework_id'])
            ->groupBy('group_id')
            ->map(fn (Collection $rows) => $rows->pluck('curricular_framework_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        return $groups->map(fn (Group $group): array => [
            'year' => (int) $group->school_year,
            'level_id' => self::id($group->education_level_id),
            'grade_id' => self::id($group->school_grade_id),
            'framework_ids' => $frameworks->get($group->id, []),
        ])->values();
    }

    /**
     * Whether an event concerns someone with these groups (see groupsOf).
     *
     * @param  Collection<int, array{year: int, level_id: int|null, grade_id: int|null, framework_ids: list<int>}>|null  $groups
     */
    public function concerns(CalendarEvent $event, ?Collection $groups): bool
    {
        if ($groups === null || $event->audiences->isEmpty()) {
            return true;
        }

        $year = (int) CarbonImmutable::instance($event->start_at)->setTimezone(SchoolTime::timezone())->year;
        $ofYear = $groups->where('year', $year);

        return $event->audiences->contains(
            fn (CalendarEventAudience $target): bool => $ofYear->contains(
                fn (array $group): bool => $this->matches($target, $group),
            ),
        );
    }

    /**
     * @param  array{year: int, level_id: int|null, grade_id: int|null, framework_ids: list<int>}  $group
     */
    protected function matches(CalendarEventAudience $target, array $group): bool
    {
        $level = self::id($target->education_level_id);
        $grade = self::id($target->school_grade_id);
        $framework = self::id($target->curricular_framework_id);

        if ($grade === null) {
            return $level !== null && $group['level_id'] === $level;
        }

        if ($group['grade_id'] !== $grade) {
            return false;
        }

        return $framework === null || in_array($framework, $group['framework_ids'], true);
    }

    protected static function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
