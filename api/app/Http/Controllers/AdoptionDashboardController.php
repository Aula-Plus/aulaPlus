<?php

namespace App\Http\Controllers;

use App\Actions\Adoption\BuildTeacherJourneys;
use App\Enums\JourneyStep;
use App\Enums\Role;
use App\Http\Requests\AdoptionTeacherJourneysRequest;
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
 *
 * Creation journeys (ClickUp 86e3qkxtc) replaced the login indicators: the
 * panel distinguishes who started creating, what they confirmed and, per
 * teacher, the journeys behind those counts — context for when a teacher
 * raises a difficulty, never a performance judgement (no ranking, no score).
 * Every journey-derived figure is `null` (shown as «dato no disponible»)
 * while the school has no record of the step it depends on — never 0.
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

    /**
     * Content keys of the per-type counts, by journey content type. Units
     * are left out on purpose: they are not counted as content of their own
     * (their classes are).
     *
     * @var array<string, string>
     */
    protected const COUNT_KEYS = [
        'annual_plan' => 'annual_plans',
        'class_session' => 'class_sessions',
        'assessment' => 'assessments',
    ];

    /**
     * Steps that show a teacher started creating: the first change in a
     * create form, or an AI generation request.
     *
     * @var list<string>
     */
    protected const STARTED_STEPS = ['filling_started', 'generation_requested'];

    public function show(School $school): AdoptionDashboardResource
    {
        $this->authorize('view-adoption-dashboard', $school);

        $teacherIds = User::query()
            ->where('school_id', $school->id)
            ->role(Role::Teacher->value)
            ->pluck('id');

        $since = now()->subDays(self::ACTIVITY_WINDOW_DAYS);
        $dataSince = $this->dataSince($school);

        $startedHasData = collect(self::STARTED_STEPS)->contains(fn (string $step) => $dataSince[$step] !== null);

        return new AdoptionDashboardResource([
            'teacher_started_rate' => $startedHasData
                ? $this->activeRate($school, $teacherIds, self::STARTED_STEPS, $since, 'step')
                : null,
            'teacher_planning_rate' => $this->activeRate($school, $teacherIds, self::PLANNING_EVENT_TYPES, $since),
            'data_since' => $dataSince,
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
     *
     * `month_counts` are confirmed creations; `month_attempts` are AI
     * generation attempts (one per journey; retries don't add), `null` per
     * type while the school has no generation record at all. Manual creation
     * has no attempts by definition — only its confirmation counts.
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

        $dataSince = $this->dataSince($school);
        $attemptsHaveData = $dataSince[JourneyStep::GenerationRequested->value] !== null;

        $attempts = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereIn('user_id', $teachers->pluck('id'))
            ->where('step', JourneyStep::GenerationRequested->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('user_id, content_type, count(*) as total')
            ->groupBy('user_id', 'content_type')
            ->get()
            ->groupBy('user_id');

        $rows = $teachers
            ->sortBy(fn (User $teacher) => mb_strtolower($teacher->name))
            ->map(function (User $teacher) use ($counts, $attempts, $attemptsHaveData): array {
                $byType = ($counts->get($teacher->id) ?? collect())->pluck('total', 'event_type');
                $attemptsByType = ($attempts->get($teacher->id) ?? collect())->pluck('total', 'content_type');

                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'subjects' => $teacher->subjects->pluck('name')->unique()->sort()->values()->all(),
                    'month_counts' => [
                        'annual_plans' => (int) ($byType['annual_plan.created'] ?? 0),
                        'class_sessions' => (int) ($byType['class_session.created'] ?? 0),
                        'assessments' => (int) ($byType['assessment.created'] ?? 0),
                    ],
                    'month_attempts' => collect(self::COUNT_KEYS)
                        ->mapWithKeys(fn (string $key, string $type) => [
                            $key => $attemptsHaveData ? (int) ($attemptsByType[$type] ?? 0) : null,
                        ])
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $rows, 'meta' => ['data_since' => $dataSince]]);
    }

    /**
     * GET /api/v1/schools/{school}/adoption-dashboard/teachers/{teacher}/journeys
     * — Dirección only: the creation journeys behind one teacher's counts for
     * a month (default: the current one), each step with its date and time.
     */
    public function teacherJourneys(
        AdoptionTeacherJourneysRequest $request,
        School $school,
        User $teacher,
        BuildTeacherJourneys $buildJourneys,
    ): JsonResponse {
        abort_unless(
            $teacher->school_id === $school->id && $teacher->hasRole(Role::Teacher->value),
            404,
        );

        $month = $request->validated('month');
        $monthStart = $month === null
            ? now()->startOfMonth()
            : Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return response()->json([
            'data' => $buildJourneys($school, $teacher, $request->user(), $monthStart),
            'meta' => [
                'month' => $monthStart->format('Y-m'),
                'data_since' => $this->dataSince($school),
            ],
        ]);
    }

    /**
     * Since when the school has data for each journey step: the earliest
     * recorded row of that step, or null («dato no disponible») when there is
     * none yet. Confirmations include the backfilled pre-journey `*.created`
     * rows, so they date back to when usage tracking started.
     *
     * @return array<string, string|null>
     */
    protected function dataSince(School $school): array
    {
        $earliest = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereNotNull('step')
            ->selectRaw('step, min(created_at) as since')
            ->groupBy('step')
            ->pluck('since', 'step');

        return collect(JourneyStep::cases())
            ->mapWithKeys(fn (JourneyStep $step) => [
                $step->value => isset($earliest[$step->value])
                    ? Carbon::parse($earliest[$step->value])->toIso8601String()
                    : null,
            ])
            ->all();
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
     * @param  list<string>  $eventTypes  values of $column to match
     * @param  'event_type'|'step'  $column
     */
    public function activeRate(School $school, $teacherIds, array $eventTypes, Carbon $since, string $column = 'event_type'): float
    {
        $totalTeachers = $teacherIds->count();

        if ($totalTeachers === 0) {
            return 0.0;
        }

        $activeCount = UsageEvent::query()
            ->where('school_id', $school->id)
            ->whereIn('user_id', $teacherIds)
            ->whereIn($column, $eventTypes)
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
