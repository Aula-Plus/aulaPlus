<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\AdoptionDashboardResource;
use App\Models\School;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/schools/{school}/adoption-dashboard — director-only, per-school
 * (docs/prompts/04-seguimiento-institucional.md §5). One of the three pilot
 * success indicators named in the VIN form — treated as a real feature, not
 * cosmetic.
 */
class AdoptionDashboardController extends Controller
{
    protected const ACTIVITY_WINDOW_DAYS = 30;

    protected const SERIES_WEEKS = 8;

    /**
     * @var list<string>
     */
    protected const PLANNING_EVENT_TYPES = [
        'annual_plan.created',
        'class_session.created',
        'assessment.created',
    ];

    public function show(School $school): AdoptionDashboardResource
    {
        $this->authorize('view-adoption-dashboard', $school);

        $teacherIds = User::query()
            ->where('school_id', $school->id)
            ->role(Role::Teacher->value)
            ->pluck('id');

        $since = now()->subDays(self::ACTIVITY_WINDOW_DAYS);

        return new AdoptionDashboardResource([
            'teacher_login_rate' => $this->activeRate($school, $teacherIds, ['login'], $since),
            'teacher_planning_rate' => $this->activeRate($school, $teacherIds, self::PLANNING_EVENT_TYPES, $since),
            'weekly_login_series' => $this->weeklySeries($school, ['login']),
            'weekly_content_series' => $this->weeklySeries($school, self::PLANNING_EVENT_TYPES),
        ]);
    }

    /**
     * % of the given teachers with at least one usage_events row of one of
     * the given types since $since. Extracted as its own method (rather than
     * inlined) so it can be exercised directly against an explicit,
     * known dataset in tests, per docs/prompts/04-seguimiento-
     * institucional.md §6.
     *
     * @param  Collection<int, int>  $teacherIds
     * @param  list<string>  $eventTypes
     */
    public function activeRate(School $school, $teacherIds, array $eventTypes, Carbon $since): float
    {
        $totalTeachers = $teacherIds->count();

        if ($totalTeachers === 0) {
            return 0.0;
        }

        $activeCount = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereIn('user_id', $teacherIds)
            ->whereIn('event_type', $eventTypes)
            ->where('created_at', '>=', $since)
            ->distinct('user_id')
            ->count('user_id');

        return round(($activeCount / $totalTeachers) * 100, 1);
    }

    /**
     * Simple weekly time series (docs/prompts/04-seguimiento-institucional.md
     * §5): one bucket per week (Monday-start), oldest first, over the last
     * SERIES_WEEKS weeks, with a zero-filled count for weeks with no events.
     *
     * @param  list<string>  $eventTypes
     * @return list<array{week_start: string, count: int}>
     */
    protected function weeklySeries(School $school, array $eventTypes): array
    {
        $weeksAgo = self::SERIES_WEEKS - 1;
        $seriesStart = now()->startOfWeek()->subWeeks($weeksAgo);

        $buckets = [];
        for ($i = $weeksAgo; $i >= 0; $i--) {
            $buckets[now()->startOfWeek()->subWeeks($i)->toDateString()] = 0;
        }

        $events = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereIn('event_type', $eventTypes)
            ->where('created_at', '>=', $seriesStart)
            ->get(['created_at']);

        foreach ($events as $event) {
            $weekStart = $event->created_at->copy()->startOfWeek()->toDateString();

            if (array_key_exists($weekStart, $buckets)) {
                $buckets[$weekStart]++;
            }
        }

        return collect($buckets)
            ->map(fn (int $count, string $week) => ['week_start' => $week, 'count' => $count])
            ->values()
            ->all();
    }
}
