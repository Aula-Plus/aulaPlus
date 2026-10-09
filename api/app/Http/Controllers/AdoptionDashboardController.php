<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\AdoptionDashboardResource;
use App\Models\School;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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
            'weekly_content_by_type' => $this->weeklySeriesByType($school, self::PLANNING_EVENT_TYPES),
        ]);
    }

    /**
     * GET /api/v1/schools/{school}/adoption-dashboard/teachers — director-only
     * "Por docente" tab (screen 28 of the living document). Plain per-teacher
     * counts for the current month, alphabetical. Deliberately no login
     * timestamps, no totals/scores and no ordering parameter: the director sees
     * who is using the platform, never a ranking or surveillance view.
     */
    public function teachers(School $school): JsonResponse
    {
        $this->authorize('view-adoption-dashboard', $school);

        $teachers = User::query()
            ->where('school_id', $school->id)
            ->role(Role::Teacher->value)
            ->with('subjects:subjects.id,subjects.name')
            ->get(['id', 'name']);

        $counts = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereIn('user_id', $teachers->pluck('id'))
            ->whereIn('event_type', self::PLANNING_EVENT_TYPES)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('user_id, event_type, count(*) as total')
            ->groupBy('user_id', 'event_type')
            ->get()
            ->groupBy('user_id');

        $rows = $teachers
            ->sortBy(fn (User $teacher) => mb_strtolower($teacher->name))
            ->map(function (User $teacher) use ($counts): array {
                $byType = ($counts->get($teacher->id) ?? collect())->pluck('total', 'event_type');

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'subjects' => $teacher->subjects->pluck('name')->unique()->sort()->values()->all(),
                    'month_counts' => [
                        'annual_plans' => (int) ($byType['annual_plan.created'] ?? 0),
                        'class_sessions' => (int) ($byType['class_session.created'] ?? 0),
                        'assessments' => (int) ($byType['assessment.created'] ?? 0),
                    ],
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/v1/schools/{school}/adoption-dashboard/teachers/{teacher}/usage
     * — the on-demand "Ver detalles de uso" for one teacher. The last login is
     * returned only as a coarse range, never as a day/time.
     */
    public function teacherUsage(School $school, User $teacher): JsonResponse
    {
        $this->authorize('view-adoption-dashboard', $school);

        abort_unless(
            $teacher->school_id === $school->id && $teacher->hasRole(Role::Teacher->value),
            404,
        );

        $lastLogin = UsageEvent::query()
            ->where('school_id', $school->id)
            ->where('user_id', $teacher->id)
            ->where('event_type', 'login')
            ->max('created_at');

        return response()->json(['data' => [
            'last_login_range' => $this->lastLoginRange($lastLogin === null ? null : Carbon::parse($lastLogin)),
        ]]);
    }

    /**
     * @return 'this_week'|'within_10_days'|'over_10_days'|'never'
     */
    protected function lastLoginRange(?Carbon $lastLogin): string
    {
        return match (true) {
            $lastLogin === null => 'never',
            $lastLogin->gte(now()->startOfWeek()) => 'this_week',
            $lastLogin->gte(now()->subDays(10)) => 'within_10_days',
            default => 'over_10_days',
        };
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

    /**
     * Same weekly buckets as weeklySeries(), split by event type for the
     * stacked chart: one entry per week with a zero-filled count per type.
     *
     * @param  list<string>  $eventTypes
     * @return list<array{week_start: string, annual_plans: int, class_sessions: int, assessments: int}>
     */
    protected function weeklySeriesByType(School $school, array $eventTypes): array
    {
        $weeksAgo = self::SERIES_WEEKS - 1;
        $seriesStart = now()->startOfWeek()->subWeeks($weeksAgo);

        $keys = [
            'annual_plan.created' => 'annual_plans',
            'class_session.created' => 'class_sessions',
            'assessment.created' => 'assessments',
        ];

        $buckets = [];
        for ($i = $weeksAgo; $i >= 0; $i--) {
            $weekStart = now()->startOfWeek()->subWeeks($i)->toDateString();
            $buckets[$weekStart] = ['week_start' => $weekStart, 'annual_plans' => 0, 'class_sessions' => 0, 'assessments' => 0];
        }

        $events = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereIn('event_type', $eventTypes)
            ->where('created_at', '>=', $seriesStart)
            ->get(['event_type', 'created_at']);

        foreach ($events as $event) {
            $weekStart = $event->created_at->copy()->startOfWeek()->toDateString();

            if (array_key_exists($weekStart, $buckets) && isset($keys[$event->event_type])) {
                $buckets[$weekStart][$keys[$event->event_type]]++;
            }
        }

        return array_values($buckets);
    }
}
