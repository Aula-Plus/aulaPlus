<?php

namespace App\Http\Controllers;

use App\Actions\Groups\BuildGroupComparison;
use App\Models\Group;
use App\Models\SchoolGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Screen 05 «Comparativa por año de cursada» (ClickUp 1dfd3mc4bgt).
 * Psicopedagogía and Dirección only (GroupPolicy::compare); groups resolve
 * through the SchoolScope, so another school's groups never appear.
 *
 * A «año de cursada» is a grade (`school_grade_id`) within a school year: only
 * those with two or more groups can be compared — Primaria included
 * (P05-DEC-07).
 */
class GroupComparisonController extends Controller
{
    /**
     * GET /api/v1/group-comparison/options — the comparable years.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('compare', Group::class);

        $options = $this->comparableGroups()
            ->map(function (Collection $groups): array {
                /** @var Group $first */
                $first = $groups->first();

                return [
                    'school_grade_id' => $first->school_grade_id,
                    'school_grade' => $first->schoolGrade?->label,
                    'school_year' => $first->school_year,
                    'position' => $first->schoolGrade?->position ?? 0,
                    'groups' => $groups->sortBy('name', SORT_NATURAL)
                        ->map(fn (Group $group): array => ['id' => $group->id, 'name' => $group->name])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy([['school_year', 'desc'], ['position', 'asc']])
            ->map(fn (array $option): array => collect($option)->except('position')->all())
            ->values()
            ->all();

        return response()->json(['data' => $options]);
    }

    /**
     * GET /api/v1/group-comparison?school_grade_id=&school_year= — the groups
     * of that year side by side.
     */
    public function show(Request $request, BuildGroupComparison $compare): JsonResponse
    {
        $this->authorize('compare', Group::class);

        $validated = $request->validate([
            'school_grade_id' => ['required', 'integer', 'exists:school_grades,id'],
            'school_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $groups = Group::query()
            ->where('school_grade_id', $validated['school_grade_id'])
            ->where('school_year', $validated['school_year'])
            ->get()
            ->filter(fn (Group $group): bool => $request->user()->can('view', $group))
            ->values();

        return response()->json(['data' => [
            'school_grade' => SchoolGrade::query()->find($validated['school_grade_id'])?->label,
            'school_year' => (int) $validated['school_year'],
            'groups' => $compare($groups, $request->user()),
        ]]);
    }

    /**
     * The school's groups that have a grade, keyed by grade + school year,
     * keeping only the years with two or more groups.
     *
     * @return Collection<string, Collection<int, Group>>
     */
    protected function comparableGroups(): Collection
    {
        return Group::query()
            ->whereNotNull('school_grade_id')
            ->whereNotNull('school_year')
            ->with('schoolGrade')
            ->get()
            ->groupBy(fn (Group $group): string => $group->school_grade_id.'-'.$group->school_year)
            ->filter(fn (Collection $groups): bool => $groups->count() >= 2);
    }
}
