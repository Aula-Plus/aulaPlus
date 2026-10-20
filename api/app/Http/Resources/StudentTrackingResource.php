<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Accommodation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The student tracking/profile aggregator (docs/prompts/04-seguimiento-
 * institucional.md §2). Wraps a plain array assembled by
 * StudentTrackingController (student model + the small, individually cached
 * query results) — see that controller for why the *raw* data is cached but
 * the role-based filtering below is not.
 *
 * Field-level gating follows the exact same pattern as StudentResource
 * (CLAUDE.md security rule 11): clinical detail (accommodations, barriers)
 * is only included for a user who passes
 * StudentPolicy::viewClinicalProfile — everyone else (e.g. a teacher who
 * teaches the student but isn't director/psychopedagogue) still gets a count
 * only, never the underlying clinical content. This is deliberately re-checked
 * on every request rather than baked into the cached payload, so the 60s
 * application cache can never leak clinical detail across roles.
 *
 * Accommodations are filtered per request through Accommodation::visibleTo
 * (ClickUp 86e3dpbwy follow-up): a teacher only gets the general ones and those
 * scoped to a subject they teach the student — a subject-specific one never
 * reaches the teachers of other subjects, not even as a chip or in the count.
 * For those, the teacher gets AccommodationSupportChipResource (name, start
 * date, subjects, assessment types) — never origin, validator or review date.
 *
 * Open alerts are filtered per alert through Alert::isVisibleTo() — the same
 * rule as AlertPolicy::view — so a teacher sees the sustained-low-performance
 * alerts addressed to them (ClickUp 86e3jpzcv), and a role that is not yet a
 * recipient sees neither the alert nor counts it. `alerts` stays absent for a
 * teacher with nothing visible, as before.
 *
 * @mixin array{
 *     student: Student,
 *     assessments: iterable,
 *     accommodations: iterable,
 *     barriers: iterable,
 *     comments: iterable,
 *     alerts: iterable,
 *     comment_trends: array<int, array<string, mixed>>,
 *     by_subject: array<int, array<string, mixed>>,
 *     overall_average: float|null,
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
            ->filter(fn ($comment) => $comment->isVisibleTo($user))
            ->values();

        $accommodations = $this->visibleAccommodations(collect($this->resource['accommodations']), $user);
        $barriers = collect($this->resource['barriers']);
        $openAlerts = EloquentCollection::make($this->resource['alerts']);
        $alerts = $openAlerts
            ->filter(fn ($alert) => $alert->isVisibleTo($user))
            ->values()
            ->load(AlertResource::RELATIONS);
        // Same rule as Alert::scopeCountableFor(): legacy alerts are still
        // counted for everyone, new ones only once they reach this viewer.
        $openAlertsCount = $openAlerts
            ->filter(fn ($alert) => $alert->recipients === null || $alerts->contains($alert))
            ->count();

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
            // Team-facing summary (no diagnosis) — readable by anyone who can view
            // the student; null until psychopedagogy confirms it.
            'team_summary' => $student->teamSummary(),
            // What to apply, since when and where (no report, validator, review
            // date or clinical detail) so a teacher knows whether a support
            // applies in their class, never the clinical record behind it (the
            // full `accommodations`/`barriers` above stay gated).
            'support_chips' => [
                'accommodations' => AccommodationSupportChipResource::collection($accommodations),
                'barriers' => $barriers
                    ->map(fn ($b) => ['id' => $b->id, 'label' => $b->description])->values(),
            ],
            'recent_comments' => CommentResource::collection($visibleComments),
            // Recurrence mark: psychopedagogy/direction only (absent for a
            // teacher, since the number anchors). A mark, not an alert.
            'comment_trends' => $this->when(
                $user->hasAnyRole(Role::schoolWideValues()),
                fn () => $this->resource['comment_trends'] ?? []
            ),
            'alerts' => $this->when(
                $canViewClinicalProfile || $alerts->isNotEmpty(),
                fn () => AlertResource::collection($alerts)
            ),
            'open_alerts_count' => $openAlertsCount,
            // Academic aggregates — not clinical, so no gating: returned to any
            // viewer of the student (decision D1/E).
            'overall_average' => $this->resource['overall_average'],
            'by_subject' => $this->resource['by_subject'],
        ];
    }

    /**
     * The accommodations the viewer may see, via the single visibility rule
     * (Accommodation::scopeVisibleTo) — one query for the whole list.
     *
     * @param  Collection<int, Accommodation>  $accommodations
     * @return Collection<int, Accommodation>
     */
    protected function visibleAccommodations(Collection $accommodations, User $user): Collection
    {
        if ($accommodations->isEmpty()) {
            return $accommodations;
        }

        $visibleIds = Accommodation::query()
            ->whereKey($accommodations->pluck('id')->all())
            ->visibleTo($user)
            ->pluck('id');

        return $accommodations
            ->filter(fn (Accommodation $accommodation): bool => $visibleIds->contains($accommodation->id))
            ->values();
    }
}
