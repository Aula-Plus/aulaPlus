<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The student tracking/profile aggregator (docs/prompts/04-seguimiento-
 * institucional.md §2). Wraps a plain array assembled by
 * StudentTrackingController (student model + the small, individually cached
 * query results) — see that controller for why the *raw* data is cached but
 * the role-based filtering below is not.
 *
 * Field-level gating follows the exact same pattern as StudentResource
 * (CLAUDE.md security rule 11): clinical detail (accommodations, barriers,
 * open alerts) is only included for a user who passes
 * StudentPolicy::viewClinicalProfile — everyone else (e.g. a teacher who
 * teaches the student but isn't director/psychopedagogue) still gets a count
 * only, never the underlying clinical content. This is deliberately re-checked
 * on every request rather than baked into the cached payload, so the 60s
 * application cache can never leak clinical detail across roles.
 *
 * @mixin array{
 *     student: Student,
 *     assessments: iterable,
 *     accommodations: iterable,
 *     barriers: iterable,
 *     comments: iterable,
 *     alerts: iterable,
 * }
 */
class StudentTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Student $student */
        $student = $this->resource['student'];
        $user = $request->user();

        $canViewClinicalProfile = Gate::forUser($user)->allows('view-clinical-profile', $student);

        $visibleComments = collect($this->resource['comments'])
            ->filter(fn ($comment) => $comment->isVisibleToRoles($user->getRoleNames()->all()))
            ->values();

        $accommodations = collect($this->resource['accommodations']);
        $barriers = collect($this->resource['barriers']);
        $alerts = collect($this->resource['alerts']);

        return [
            'student' => new StudentResource($student),
            'recent_assessments' => AssessmentSummaryResource::collection($this->resource['assessments']),
            'accommodations' => $this->when(
                $canViewClinicalProfile,
                fn () => AccommodationResource::collection($accommodations)
            ),
            'accommodations_count' => $accommodations->count(),
            'barriers' => $this->when(
                $canViewClinicalProfile,
                fn () => BarrierResource::collection($barriers)
            ),
            'barriers_count' => $barriers->count(),
            'recent_comments' => CommentResource::collection($visibleComments),
            'alerts' => $this->when(
                $canViewClinicalProfile,
                fn () => AlertResource::collection($alerts)
            ),
            'open_alerts_count' => $alerts->count(),
        ];
    }
}
