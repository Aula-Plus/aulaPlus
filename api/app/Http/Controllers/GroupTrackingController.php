<?php

namespace App\Http\Controllers;

use App\Http\Resources\GroupTrackingResource;
use App\Models\Alert;
use App\Models\Assessment;
use App\Models\Comment;
use App\Models\Group;

/**
 * GET /api/v1/groups/{group}/tracking — read-only aggregator view
 * (docs/prompts/04-seguimiento-institucional.md §3), not cached (unlike the
 * student tracking view — see StudentTrackingController): its per-student
 * summaries are counts/booleans only for every viewer, so there is no
 * role-based filtering to protect from a shared cache in the first place.
 */
class GroupTrackingController extends Controller
{
    /**
     * How far back "the period" for the group trend looks. Not specified by
     * the domain doc beyond "el período" — 30 days chosen to match the other
     * 30-day windows already used elsewhere (e.g. the adoption dashboard).
     */
    protected const TREND_PERIOD_DAYS = 30;

    public function show(Group $group): GroupTrackingResource
    {
        $this->authorize('view', $group);

        $since = now()->subDays(self::TREND_PERIOD_DAYS);

        $students = $group->students()->get()->map(fn ($student) => [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'open_alerts_count' => Alert::query()->where('student_id', $student->id)->where('resolved', false)->count(),
            'has_active_accommodations' => $student->accommodations()->get()->contains->isEffective(),
        ]);

        return new GroupTrackingResource([
            'group' => $group,
            'students' => $students,
            'period_days' => self::TREND_PERIOD_DAYS,
            'assessments_count' => Assessment::query()
                ->where('group_id', $group->id)
                ->where('created_at', '>=', $since)
                ->count(),
            'comments_count' => Comment::query()
                ->where('commentable_type', Group::class)
                ->where('commentable_id', $group->id)
                ->where('created_at', '>=', $since)
                ->count(),
        ]);
    }
}
