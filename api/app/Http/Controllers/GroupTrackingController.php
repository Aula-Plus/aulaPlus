<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\GroupTrackingResource;
use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Comment;
use App\Models\Group;
use Illuminate\Http\Request;

/**
 * GET /api/v1/groups/{group}/tracking — read-only aggregator view
 * (docs/prompts/04-seguimiento-institucional.md §3), not cached (unlike the
 * student tracking view — see StudentTrackingController): its per-student
 * summaries are counts/booleans only — though still filtered per viewer
 * (alerts by recipient; the accommodation flag for school-wide roles only),
 * which is one more reason not to share a cache.
 */
class GroupTrackingController extends Controller
{
    /**
     * How far back "the period" for the group trend looks. Not specified by
     * the domain doc beyond "el período" — 30 days chosen to match the other
     * 30-day windows already used elsewhere (e.g. the adoption dashboard).
     */
    protected const TREND_PERIOD_DAYS = 30;

    public function show(Request $request, Group $group): GroupTrackingResource
    {
        $this->authorize('view', $group);

        $user = $request->user();

        // The Evaluaciones screen reads `group.teachers` to decide whether the
        // authed teacher leads the group (canManageAssessments). GroupResource
        // only emits `teachers` when the relation is loaded, so load it here —
        // otherwise the key is omitted and the SPA white-screens for teachers.
        $group->loadMissing('teachers');

        $since = now()->subDays(self::TREND_PERIOD_DAYS);

        // Alphabetical by full_name, never by any indicator (open alerts,
        // accommodations): the group profile lists students, it does not rank
        // them (docs/prompts/21-perfil-de-grupo.md §3). Qualify the column —
        // the relation resolves through a join over the group_student pivot.
        $students = $group->students()->orderBy('students.full_name')->get();

        // Only psychopedagogy and direction get the per-student accommodation
        // flag. For a teacher it would be a roster of who has accommodations —
        // "agregado por tipo, nunca nómina" (pantallas 02 y 03): they read the
        // aggregate table instead, and reach a single case only by opening the
        // student file. One query for the whole group, through the single
        // visibility rule (Accommodation::scopeVisibleTo). "Active" keeps the
        // isEffective() semantics (active + approved when required).
        $withActiveAccommodations = $user->hasAnyRole(Role::schoolWideValues())
            ? Accommodation::query()
                ->visibleTo($user)
                ->effective()
                ->whereIn('accommodations.student_id', $students->modelKeys())
                ->distinct()
                ->pluck('accommodations.student_id')
                ->flip()
            : null;

        // Alert::scopeCountableFor: a role that isn't a recipient of a new
        // alert yet must not learn it exists. One grouped query for the whole
        // group rather than a COUNT per student.
        $openAlertsCounts = Alert::query()
            ->whereIn('alerts.student_id', $students->modelKeys())
            ->where('alerts.resolved', false)
            ->countableFor($user)
            ->groupBy('alerts.student_id')
            ->selectRaw('alerts.student_id, count(*) as open_alerts_count')
            ->pluck('open_alerts_count', 'student_id');

        $students = $students->map(fn ($student) => [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'open_alerts_count' => (int) ($openAlertsCounts[$student->id] ?? 0),
            'has_active_accommodations' => $withActiveAccommodations?->has($student->id),
        ]);

        return new GroupTrackingResource([
            'group' => $group,
            'students' => $students,
            'period_days' => self::TREND_PERIOD_DAYS,
            'comments_count' => Comment::query()
                ->where('commentable_type', Group::class)
                ->where('commentable_id', $group->id)
                ->where('created_at', '>=', $since)
                ->count(),
        ]);
    }
}
