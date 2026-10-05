<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentTrackingResource;
use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Barrier;
use App\Models\Comment;
use App\Models\Student;
use App\Models\User;
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

    public function __construct(protected StudentGradeAccess $gradeAccess) {}

    public function show(Student $student): StudentTrackingResource
    {
        $this->authorize('view', $student);
        $user = request()->user();

        // The Resource renders the student's classes, so the relation must be
        // eager-loaded here — StudentResource exposes `groups` only whenLoaded,
        // and the SPA's "Clases" row expects it present.
        $student->load('groups');

        // The cache key carries a schema version: bump it whenever the cached
        // aggregate shape changes (e.g. adding by_subject/overall_average) so a
        // stale pre-deploy entry can't be read as the new shape for up to 60s.
        $cached = Cache::remember(
            "student-tracking.v3.{$student->id}",
            60,
            fn () => $this->aggregate($student)
        );

        return new StudentTrackingResource([
            'student' => $student,
            'assessments' => $this->hydrate(Assessment::class, $cached['assessments']),
            'accommodations' => $this->hydrate(Accommodation::class, $cached['accommodations']),
            'barriers' => $this->hydrate(Barrier::class, $cached['barriers']),
            'comments' => $this->hydrate(Comment::class, $cached['comments']),
            'alerts' => $this->hydrate(Alert::class, $cached['alerts']),
            // Grades are role-dependent (a teacher only sees their own subjects
            // unless direction opened more), so they are computed per request,
            // never taken from the shared cache.
            'by_subject' => $this->subjectAggregates($student, $user),
            'overall_average' => $this->overallAverage($student, $user),
            'grades' => $this->grades($student, $user),
            'grades_view' => $this->gradeAccess->describe($user, $student),
        ]);
    }

    /**
     * Cache-safe snapshot: each model-backed key holds a list of raw attribute
     * arrays (primitive scalars only), never hydrated models — see the class
     * docblock. The academic aggregate keys (`by_subject`, `overall_average`)
     * are likewise plain scalars.
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
     * in, the mean score and number of scored assessments. Academic data — not
     * clinical — so it is cached and returned to anyone who can view the student
     * (no clinical gate). Ordered by subject name for a stable UI.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subjectAggregates(Student $student, User $user): array
    {
        return $this->visibleResults($student, $user)
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
     * Mean of all the student's assessment-result scores, or null if none.
     */
    protected function overallAverage(Student $student, User $user): ?float
    {
        $avg = $this->visibleResults($student, $user)->avg('assessment_results.score');

        return $avg === null ? null : round((float) $avg, 2);
    }

    /**
     * Every scored instance the viewer may see, newest first: the subject, the
     * date it was administered and the score.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function grades(Student $student, User $user): array
    {
        return $this->visibleResults($student, $user)
            ->join('subjects', 'subjects.id', '=', 'assessments.subject_id')
            ->orderByDesc('assessments.administered_at')
            ->orderByDesc('assessment_results.id')
            ->select('assessment_results.id', 'assessment_results.score', 'assessments.id as assessment_id',
                'assessments.type', 'assessments.administered_at', 'subjects.id as subject_id',
                'subjects.name as subject_name')
            ->limit(100)
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
     * @return Builder<AssessmentResult>
     */
    protected function visibleResults(Student $student, User $user): Builder
    {
        return $this->gradeAccess->restrict(
            AssessmentResult::query()
                ->where('assessment_results.student_id', $student->id)
                ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id'),
            $user,
            $student,
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
