<?php

namespace App\Actions\Groups;

use App\Models\AssessmentResult;
use App\Models\Group;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestResult;
use App\Models\User;
use App\Services\StudentGradeAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Screen 05 «Comparativa por año de cursada» (ClickUp 1dfd3mc4bgt): the
 * groups of one grade side by side. Strictly aggregate — every value is of the
 * whole group, never a student's (P05-DEC-02), and no student identifier is
 * returned. Groups come in name order, never ranked (P05-DEC-01).
 *
 * Nothing is computed only for this screen (P05-DEC-03): the subject averages
 * come from the loaded assessment results (numeric scores, as the group's
 * performance line), the accommodations from BuildGroupAccommodationSummary
 * (the group profile table), and the screenings from the results of each
 * screening's latest application to the group with loaded bands, counted by
 * the band names the school configured.
 */
class BuildGroupComparison
{
    public function __construct(
        protected BuildGroupAccommodationSummary $accommodations,
        protected StudentGradeAccess $gradeAccess,
    ) {}

    /**
     * @param  Collection<int, Group>  $groups
     * @return list<array<string, mixed>>
     */
    public function __invoke(Collection $groups, User $viewer): array
    {
        return $groups
            ->sortBy('name', SORT_NATURAL)
            ->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'student_count' => $group->students()->count(),
                'subject_averages' => $this->subjectAverages($group, $viewer),
                'accommodations_by_type' => ($this->accommodations)($group, $viewer),
                'screenings' => Gate::forUser($viewer)->allows('viewAny', [ScreeningTestApplication::class, $group])
                    ? $this->screenings($group)
                    : [],
            ])
            ->values()
            ->all();
    }

    /**
     * Mean of the numeric results loaded for the group's assessments, per
     * subject. A scale-level result (Primaria) has no number and is never
     * converted to one (PA-088), as in the group's performance line; it is
     * counted in `level_results_count` so the screen can tell «no numeric
     * grade» apart from «nothing loaded». `average` is null when a subject
     * only has scale-level results.
     *
     * @return list<array{subject_id: int, subject_name: string, average: float|null, results_count: int, level_results_count: int}>
     */
    protected function subjectAverages(Group $group, User $viewer): array
    {
        $rule = $this->gradeAccess->forGroup($viewer, $group);

        return $rule->apply(AssessmentResult::query())
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->where('assessments.group_id', $group->id)
            ->where(fn ($loaded) => $loaded
                ->whereNotNull('assessment_results.score')
                ->orWhereNotNull('assessment_results.official_scale_level_id'))
            ->groupBy('assessments.subject_id', 'subjects.name')
            ->orderBy('subjects.name')
            ->selectRaw('assessments.subject_id as subject_id, subjects.name as subject_name, avg(assessment_results.score) as average, count(assessment_results.score) as results_count, sum(case when assessment_results.score is null then 1 else 0 end) as level_results_count')
            ->get()
            ->map(fn ($row): array => [
                'subject_id' => (int) $row->subject_id,
                'subject_name' => (string) $row->subject_name,
                'average' => $row->average === null ? null : round((float) $row->average, 1),
                'results_count' => (int) $row->results_count,
                'level_results_count' => (int) $row->level_results_count,
            ])
            ->values()
            ->all();
    }

    /**
     * For each screening applied to the group, its latest application with
     * banded results counted by band, in the order of the design's bands (lowest first).
     *
     * @return list<array{screening_test_type_id: int, name: string, application_date: string|null, bands: list<array{name: string, count: int}>}>
     */
    protected function screenings(Group $group): array
    {
        // The latest application with banded results wins: a new one whose
        // scores are not loaded yet must not hide the previous one. A type
        // with no banded application at all still shows (with no bands).
        return ScreeningTestApplication::query()
            ->where('group_id', $group->id)
            ->with('type')
            ->withExists(['results as has_bands' => fn ($results) => $results->whereNotNull('band')])
            ->orderByDesc('has_bands')
            ->orderByDesc('application_date')
            ->orderByDesc('id')
            ->get()
            ->unique('screening_test_type_id')
            ->map(function (ScreeningTestApplication $application): array {
                $results = ScreeningTestResult::query()
                    ->where('screening_test_application_id', $application->id)
                    ->whereNotNull('band')
                    ->with('design')
                    ->get();
                $counts = $results->countBy('band');
                $order = collect($results->first()?->design?->bandList() ?? [])->pluck('name');

                return [
                    'screening_test_type_id' => $application->screening_test_type_id,
                    'name' => (string) $application->type?->name,
                    'application_date' => $application->application_date?->toDateString(),
                    // Every band of the design, even when nobody fell in it;
                    // a band no longer in the design goes last.
                    'bands' => $order->merge($counts->keys()->diff($order))
                        ->map(fn (string $name): array => ['name' => $name, 'count' => (int) ($counts[$name] ?? 0)])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }
}
