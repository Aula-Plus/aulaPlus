<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentTrackingResource;
use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Barrier;
use App\Models\Comment;
use App\Models\Student;
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

    public function show(Student $student): StudentTrackingResource
    {
        $this->authorize('view', $student);

        // The Resource renders the student's classes, so the relation must be
        // eager-loaded here — StudentResource exposes `groups` only whenLoaded,
        // and the SPA's "Clases" row expects it present.
        $student->load('groups');

        $cached = Cache::remember(
            "student-tracking.{$student->id}",
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
        ]);
    }

    /**
     * Cache-safe snapshot: each key holds a list of raw attribute arrays
     * (primitive scalars only), never hydrated models — see the class docblock.
     *
     * @return array<string, array<int, array<string, mixed>>>
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
