<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentTrackingResource;
use App\Models\Assessment;
use App\Models\Student;
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
 */
class StudentTrackingController extends Controller
{
    protected const RECENT_ASSESSMENTS_LIMIT = 5;

    protected const RECENT_COMMENTS_LIMIT = 10;

    public function show(Student $student): StudentTrackingResource
    {
        $this->authorize('view', $student);

        $cached = Cache::remember(
            "student-tracking.{$student->id}",
            60,
            fn () => $this->aggregate($student)
        );

        return new StudentTrackingResource(['student' => $student, ...$cached]);
    }

    /**
     * @return array<string, iterable>
     */
    protected function aggregate(Student $student): array
    {
        $groupIds = $student->groups()->pluck('groups.id');

        return [
            // No direct Student-Assessment relation in the domain model — an
            // assessment is inferred by the student's group membership
            // (docs/prompts/04-seguimiento-institucional.md §2).
            'assessments' => Assessment::query()
                ->whereIn('group_id', $groupIds)
                ->latest()
                ->take(self::RECENT_ASSESSMENTS_LIMIT)
                ->get(),
            'accommodations' => $student->accommodations()->get()->filter->isEffective()->values(),
            'barriers' => $student->barriers()->where('active', true)->get(),
            'comments' => $student->comments()->latest()->take(self::RECENT_COMMENTS_LIMIT)->get(),
            'alerts' => $student->alerts()->where('resolved', false)->get(),
        ];
    }
}
