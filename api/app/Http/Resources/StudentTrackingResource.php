<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * (CLAUDE.md security rule 11): clinical detail (accommodations, barriers)
 * is only included for a user who passes
 * StudentPolicy::viewClinicalProfile — everyone else (e.g. a teacher who
 * teaches the student but isn't director/psychopedagogue) still gets a count
 * only, never the underlying clinical content. This is deliberately re-checked
 * on every request rather than baked into the cached payload, so the 60s
 * application cache can never leak clinical detail across roles.
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

        $accommodations = collect($this->resource['accommodations']);
        $barriers = collect($this->resource['barriers']);
        $openAlerts = EloquentCollection::make($this->resource['alerts']);
        $alerts = $openAlerts
            ->filter(fn ($alert) => $alert->isVisibleTo($user))
            ->values()
            ->load('subject:id,name');
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
            // Names only (no report, validator or clinical detail) so a teacher
            // sees WHICH supports apply, never the clinical record behind them
            // (the full `accommodations`/`barriers` above stay gated).
            'support_chips' => [
                'accommodations' => $accommodations
                    ->map(fn ($a) => ['id' => $a->id, 'label' => $a->type])->values(),
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
}
