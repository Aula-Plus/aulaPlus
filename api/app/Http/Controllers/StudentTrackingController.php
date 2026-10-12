<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentTrackingResource;
use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Barrier;
use App\Models\Comment;
use App\Models\Student;
use App\Models\User;
use App\Services\CommentTrendDetector;
use App\Services\GradeAccessRule;
use App\Services\StudentGradeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * GET /api/v1/students/{student}/tracking — read-only aggregator view over
 * several small queries (docs/prompts/04-seguimiento-institucional.md §2),
 * not a CRUD resource.
 *
 * The *raw* aggregated data (before any role-based filtering) is cached for
 * 60 seconds — application cache via Cache::remember, not an HTTP cache
 * header, per spec. Caching the raw data rather than the final JSON is
 * deliberate: StudentTrackingResource re-applies clinical-profile gating and
 * comment visible_to filtering on every request against the current user, so
 * a value cached for one viewer's role can never leak to another viewer with
 * less access (see that Resource's docblock).
 *
 * We cache each row's *raw attributes* (plain scalars) rather than hydrated
 * Eloquent models on purpose: real cache stores (database/file/redis)
 * unserialize with Laravel's secure `serializable_classes => false` default,
 * which turns any cached object into an __PHP_Incomplete_Class. Caching
 * primitives keeps that secure default untouched; models are rebuilt per
 * request via {@see self::hydrate()} before the Resource sees them.
 */
class StudentTrackingController extends Controller
{
    protected const RECENT_ASSESSMENTS_LIMIT = 5;

    protected const RECENT_COMMENTS_LIMIT = 10;

    /** Most recent grade rows listed in `grades`; the aggregates use all of them. */
    public const GRADES_LIMIT = 100;

    public function __construct(protected StudentGradeAccess $gradeAccess) {}

    public function show(Student $student): StudentTrackingResource
    {
        $this->authorize('view', $student);
        $user = request()->user();

        // The Resource renders the student's classes, so the relation must be
        // eager-loaded here — StudentResource exposes `groups` only whenLoaded,
        // and the SPA's "Clases" row expects it present.
        $student->load('groups');

        // Resolved once and applied to every grade query below (school setting,
        // the teacher's subjects and the last cutoff are looked up once).
        $gradeRule = $this->gradeAccess->forStudent($user, $student);
        $grades = $this->grades($student, $gradeRule);

        // The cache key carries a schema version: bump it whenever the cached
        // aggregate shape changes (e.g. adding by_subject/overall_average) so a
        // stale pre-deploy entry can't be read as the new shape for up to 60s.
        // (Dropping keys, as when grades left the cache, needs no bump: a stale
        // entry's extra keys are simply ignored.)
        $cached = Cache::remember(
            "student-tracking.v4.{$student->id}",
            60,
            fn () => $this->aggregate($student)
        );

        return new StudentTrackingResource([
            'student' => $student,
            'assessments' => $this->hydrate(Assessment::class, $cached['assessments']),
            // Subjects are loaded per request (not cached): the Resource filters
            // accommodations by the viewer's subjects and renders their scope.
            'accommodations' => $this->hydrate(Accommodation::class, $cached['accommodations'])->load('subjects'),
            'barriers' => $this->hydrate(Barrier::class, $cached['barriers']),
            'comments' => $this->hydrate(Comment::class, $cached['comments'])->load('categories'),
            // Never cached: depends on the asking user (visibility + role).
            'comment_trends' => app(CommentTrendDetector::class)->forStudent($student, request()->user()),
            'alerts' => $this->hydrate(Alert::class, $cached['alerts']),
            // Grades are role-dependent (a teacher only sees their own subjects
            // unless direction opened more), so they are computed per request,
            // never taken from the shared cache.
            'by_subject' => $this->subjectAggregates($student, $gradeRule),
            // Null for a teacher limited to their own subjects: they get only
            // their subjects' averages (GradeAccessRule::showsOverallAverage).
            'overall_average' => $gradeRule->showsOverallAverage()
                ? $this->overallAverage($student, $gradeRule)
                : null,
            'grades' => $this->withAccommodationsInForce(array_slice($grades, 0, self::GRADES_LIMIT), $student, $user),
            'grades_truncated' => count($grades) > self::GRADES_LIMIT,
            'grades_view' => $gradeRule->describe(),
        ]);
    }

    /**
     * Cache-safe snapshot: each model-backed key holds a list of raw attribute
     * arrays (primitive scalars only), never hydrated models — see the class
     * docblock. Grades (`by_subject`, `overall_average`, `grades`) are NOT part
     * of it: they depend on the viewer, see {@see self::show()}.
     *
     * @return array<string, mixed>
     */
    protected function aggregate(Student $student): array
    {
        $groupIds = $student->groups()->pluck('groups.id');

        return [
            // No direct Student-Assessment relation in the domain model — an
            // assessment is inferred by the student's group membership
            // (docs/prompts/04-seguimiento-institucional.md §2).
            'assessments' => $this->rawAttributes(
                Assessment::query()
                    ->whereIn('group_id', $groupIds)
                    ->latest()
                    ->take(self::RECENT_ASSESSMENTS_LIMIT)
                    ->get()
            ),
            'accommodations' => $this->rawAttributes(
                $student->accommodations()->get()->filter->isEffective()->values()
            ),
            'barriers' => $this->rawAttributes(
                $student->barriers()->where('active', true)->get()
            ),
            'comments' => $this->rawAttributes(
                $student->comments()->latest()->take(self::RECENT_COMMENTS_LIMIT)->get()
            ),
            'alerts' => $this->rawAttributes(
                $student->alerts()->where('resolved', false)->get()
            ),
        ];
    }

    /**
     * Academic per-subject aggregates: for each subject the student has results
     * in, the mean score and number of scored assessments, over the results
     * the viewer may see (never cached: it depends on the viewer). Academic data
     * — not clinical — so there is no clinical gate. Ordered by subject name for
     * a stable UI.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subjectAggregates(Student $student, GradeAccessRule $rule): array
    {
        return $this->visibleResults($student, $rule)
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->groupBy('subjects.id', 'subjects.name')
            ->orderBy('subjects.name')
            // COUNT(score), not COUNT(*): a null score does not contribute to
            // AVG, so it must not be counted either — keep count and average on
            // the same denominator (the scored results).
            ->selectRaw('subjects.id as subject_id, subjects.name as subject_name, '
                .'ROUND(AVG(assessment_results.score), 2) as average, '
                .'COUNT(assessment_results.score) as assessment_count')
            ->get()
            ->map(fn ($row) => [
                'subject_id' => (int) $row->subject_id,
                'subject_name' => $row->subject_name,
                'average' => (float) $row->average,
                'assessment_count' => (int) $row->assessment_count,
            ])
            ->all();
    }

    /**
     * Mean of the student's assessment-result scores the viewer may see, or
     * null if none.
     */
    protected function overallAverage(Student $student, GradeAccessRule $rule): ?float
    {
        $avg = $this->visibleResults($student, $rule)->avg('assessment_results.score');

        return $avg === null ? null : round((float) $avg, 2);
    }

    /**
     * The scored instances the viewer may see, newest first: the subject, the
     * date it was administered and the score. Fetches one row past
     * GRADES_LIMIT so show() can flag the list as truncated (`grades_truncated`)
     * — `by_subject`/`overall_average` always cover every visible grade.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function grades(Student $student, GradeAccessRule $rule): array
    {
        return $this->visibleResults($student, $rule)
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->orderByDesc('assessments.administered_at')
            ->orderByDesc('assessment_results.id')
            ->select('assessment_results.id', 'assessment_results.score', 'assessments.id as assessment_id',
                'assessments.type', 'assessments.administered_at', 'subjects.id as subject_id',
                'subjects.name as subject_name')
            ->limit(self::GRADES_LIMIT + 1)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'assessment_id' => (int) $row->assessment_id,
                'subject_id' => (int) $row->subject_id,
                'subject_name' => $row->subject_name,
                'type' => $row->type,
                'administered_at' => $row->administered_at
                    ? substr((string) $row->administered_at, 0, 10) : null,
                'score' => $row->score === null ? null : (float) $row->score,
            ])
            ->all();
    }

    /**
     * Next to each grade, the accommodations that were in force for it (living
     * document screen 04 · Evaluaciones: «cada instancia con su nota y, al
     * lado, qué ajustes estaban activos ese día»; PO ClickUp 86e3d1bwa):
     *
     * - in force on the instance's date (Accommodation::isInForceOn — started
     *   by then, not switched off before or on it);
     * - applying to that instance's subject and assessment type;
     * - not deactivated for that very instance (AccommodationInstanceOverride);
     * - and only those the viewer may see (Accommodation::scopeVisibleTo — a
     *   teacher never gets one scoped to a subject they don't teach).
     *
     * Each one is just `{id, label}` for every viewer (CLAUDE.md rule 11, the
     * AccommodationSupportChipResource rule): never the description, origin,
     * validator or the override reason. Two queries per request (the student's
     * visible accommodations with their subjects, and the overrides of the
     * listed instances), matched in memory.
     *
     * @param  array<int, array<string, mixed>>  $grades
     * @return array<int, array<string, mixed>>
     */
    protected function withAccommodationsInForce(array $grades, Student $student, User $user): array
    {
        if ($grades === []) {
            return $grades;
        }

        $accommodations = Accommodation::query()
            ->where('student_id', $student->id)
            ->visibleTo($user)
            ->with('subjects')
            ->orderBy('type')
            ->get();

        $overridden = $accommodations->isEmpty()
            ? collect()
            : AccommodationInstanceOverride::query()
                ->whereIn('assessment_id', array_unique(array_column($grades, 'assessment_id')))
                ->whereIn('accommodation_id', $accommodations->modelKeys())
                ->get(['accommodation_id', 'assessment_id'])
                ->mapWithKeys(fn (AccommodationInstanceOverride $override) => [
                    "{$override->assessment_id}:{$override->accommodation_id}" => true,
                ]);

        return array_map(function (array $grade) use ($accommodations, $overridden): array {
            $grade['accommodations'] = $grade['administered_at'] === null ? [] : $accommodations
                ->filter(fn (Accommodation $accommodation): bool => $accommodation->isInForceOn($grade['administered_at'])
                    && $accommodation->appliesIn($grade['subject_id'], $grade['type'])
                    && ! $overridden->has("{$grade['assessment_id']}:{$accommodation->id}"))
                ->map(fn (Accommodation $accommodation): array => [
                    'id' => $accommodation->id,
                    'label' => $accommodation->type,
                ])
                ->values()
                ->all();

            return $grade;
        }, $grades);
    }

    /**
     * @return Builder<AssessmentResult>
     */
    protected function visibleResults(Student $student, GradeAccessRule $rule): Builder
    {
        return $rule->apply(
            AssessmentResult::query()
                ->where('assessment_results.student_id', $student->id)
                ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id'),
        );
    }

    /**
     * Reduce a model collection to its pristine raw DB attributes — casts are
     * intentionally not resolved (getRawOriginal, not getAttributes), so the
     * result is serialize-safe and re-hydratable.
     *
     * @param  \Illuminate\Support\Collection<int, Model>  $models
     * @return array<int, array<string, mixed>>
     */
    protected function rawAttributes($models): array
    {
        return $models->map->getRawOriginal()->all();
    }

    /**
     * Rebuild an Eloquent collection from cached raw attributes. hydrate()
     * marks each model as existing and re-applies casts on access, so the
     * Resource and its nested resources behave exactly as with a live query.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, Model>
     */
    protected function hydrate(string $model, array $rows): Collection
    {
        return $model::hydrate($rows);
    }
}
