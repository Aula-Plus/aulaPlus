<?php

namespace App\Http\Controllers;

use App\Enums\SchoolYearStatus;
use App\Http\Requests\SchoolYears\CalendarBodyRequest;
use App\Http\Requests\SchoolYears\CloseSchoolYearRequest;
use App\Http\Requests\SchoolYears\OpenNextSchoolYearRequest;
use App\Http\Requests\SchoolYears\ReopenSchoolYearRequest;
use App\Http\Requests\SchoolYears\SchoolPeriodRequest;
use App\Http\Requests\SchoolYears\StoreGroupWriteWindowRequest;
use App\Http\Requests\SchoolYears\UpdateSchoolYearRequest;
use App\Models\Accommodation;
use App\Models\CurricularFramework;
use App\Models\Group;
use App\Models\GroupWriteWindow;
use App\Models\SchoolPeriod;
use App\Models\SchoolYear;
use App\Services\SchoolYears\PeriodCalendars;
use App\Services\SchoolYears\SchoolYearCalendar;
use App\Support\SchoolTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7), direction only
 * (SchoolYearPolicy):
 *
 * - GET  /v1/school-year — the current year, its periods per row (one per
 *   body of the school's offer, or per program once Cambridge or IB is split,
 *   PeriodCalendars), what closing carries over and the groups of closed
 *   years loading can be re-enabled in.
 * - PUT  /v1/school-years/{id} — the day closing is enabled from.
 * - POST /v1/school-years/{id}/periods, PATCH/DELETE /v1/school-periods/{id}.
 * - POST /v1/school-years/{id}/calendar-bodies/{body}/split|join —
 *   «Separar por programa» in Cambridge or IB, and back.
 * - POST /v1/school-years/{id}/close — deletes and moves nothing: the groups
 *   stay as history (read-only, see GroupPolicy::writeInYear) and the
 *   accommodations stay in force because they live on the student.
 * - POST /v1/school-years/{id}/reopen — «Deshacer el cierre», recording who
 *   and when; only while no later year exists.
 * - POST /v1/school-years/{id}/open-next — opens the next year carrying each
 *   period as decided (same date / other date / not carried).
 * - POST /v1/school-years/{id}/write-windows, POST /v1/group-write-windows/
 *   {id}/end — loading in one group of a closed year until a date.
 */
class SchoolYearController extends Controller
{
    public function __construct(
        private SchoolYearCalendar $calendar,
        private PeriodCalendars $calendars,
    ) {}

    public function show(Request $request): JsonResponse
    {
        // Before the year is looked up: a first visit creates it.
        $this->authorize('viewAny', SchoolYear::class);
        $year = $this->calendar->current($request->user()->school_id);
        $this->authorize('view', $year);

        return response()->json(['data' => $this->payload($year)]);
    }

    public function update(UpdateSchoolYearRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        $schoolYear->update(['closing_enabled_from' => $request->validated('closing_enabled_from')]);

        return response()->json(['data' => $this->payload($schoolYear)]);
    }

    public function storePeriod(SchoolPeriodRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        $schoolYear->periods()->create($request->validated());

        return response()->json(['data' => $this->payload($schoolYear)], 201);
    }

    public function updatePeriod(SchoolPeriodRequest $request, SchoolPeriod $period): JsonResponse
    {
        $period->update($request->validated());

        return response()->json(['data' => $this->payload($period->schoolYear)]);
    }

    public function destroyPeriod(Request $request, SchoolPeriod $period): Response|JsonResponse
    {
        $year = $period->schoolYear;
        $this->authorize('update', $year);
        if (! $year->isOpen()) {
            return response()->json([
                'message' => 'The periods of a closed school year cannot change.',
                'errors' => ['period' => ['The periods of a closed school year cannot change.']],
            ], 422);
        }

        $period->delete();

        return response()->noContent();
    }

    /** «Separar por programa» (Cambridge, IB). */
    public function splitBody(CalendarBodyRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        $this->calendars->split($schoolYear, $request->body());

        return response()->json(['data' => $this->payload($schoolYear)]);
    }

    /** Back to one row for the body, when its programs have the same periods. */
    public function joinBody(CalendarBodyRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        $this->calendars->join($schoolYear, $request->body());

        return response()->json(['data' => $this->payload($schoolYear)]);
    }

    public function close(CloseSchoolYearRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        DB::transaction(function () use ($request, $schoolYear): void {
            // Re-checked under the row lock: two closings at once record one.
            $locked = $this->lock($schoolYear);
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['year' => 'The school year is already closed.']);
            }
            $locked->update([
                'status' => SchoolYearStatus::Closed,
                'closed_at' => now(),
                'closed_by_id' => $request->user()->id,
            ]);
        });
        $this->calendar->forget();

        return response()->json(['data' => $this->payload($schoolYear)]);
    }

    /**
     * «Deshacer el cierre»: the year is open again and its groups writable.
     * Nothing else changes — the periods, the enablements (kept as records)
     * and everything loaded stay as they were. Who and when is recorded on
     * the year (and, like every change of the year, in the audit trail).
     */
    public function reopen(ReopenSchoolYearRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        DB::transaction(function () use ($request, $schoolYear): void {
            $locked = $this->lock($schoolYear);
            $later = SchoolYear::query()->where('school_id', $locked->school_id)
                ->where('year', '>', $locked->year)->exists();
            if (! $locked->isClosed() || $later) {
                throw ValidationException::withMessages(['year' => 'The next school year is already open: closing cannot be undone.']);
            }
            $locked->update([
                'status' => SchoolYearStatus::Open,
                'closed_at' => null,
                'closed_by_id' => null,
                'reopened_at' => now(),
                'reopened_by_id' => $request->user()->id,
            ]);
        });
        $this->calendar->forget();

        return response()->json(['data' => $this->payload($schoolYear)]);
    }

    public function openNext(OpenNextSchoolYearRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        $decisions = collect($request->validated('periods'))->keyBy(fn ($row) => (int) $row['id']);

        try {
            $next = DB::transaction(fn (): SchoolYear => $this->createNext($request, $schoolYear, $decisions));
        } catch (UniqueConstraintViolationException) {
            // Two openings at once: the other one won.
            throw ValidationException::withMessages(['year' => 'The next school year is already open.']);
        }
        $this->calendar->forget();

        return response()->json(['data' => $this->payload($next)], 201);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $decisions
     */
    private function createNext(OpenNextSchoolYearRequest $request, SchoolYear $schoolYear, Collection $decisions): SchoolYear
    {
        $locked = $this->lock($schoolYear);
        $exists = SchoolYear::query()->where('school_id', $locked->school_id)
            ->where('year', '>', $locked->year)->exists();
        if (! $locked->isClosed() || $exists) {
            throw ValidationException::withMessages(['year' => 'The next school year is already open.']);
        }

        $next = SchoolYear::query()->forceCreate([
            'school_id' => $schoolYear->school_id,
            'year' => $schoolYear->year + 1,
            'status' => SchoolYearStatus::Open,
            'opened_at' => now(),
            'opened_by_id' => $request->user()->id,
            // Cambridge or IB split by program stays split.
            'split_calendar_bodies' => $locked->split_calendar_bodies,
        ]);

        foreach ($schoolYear->periods as $period) {
            $decision = $decisions[$period->id];
            $startsOn = match ($decision['action']) {
                'same_date' => $period->starts_on->copy()->addYearNoOverflow()->toDateString(),
                'other_date' => $decision['starts_on'],
                default => null,
            };
            if ($startsOn === null) {
                continue;
            }
            $next->periods()->forceCreate([
                'school_id' => $schoolYear->school_id,
                'calendar_body' => $period->calendar_body,
                'curricular_framework_id' => $period->curricular_framework_id,
                'name' => $period->name,
                'starts_on' => $startsOn,
            ]);
        }

        return $next;
    }

    private function lock(SchoolYear $year): SchoolYear
    {
        return SchoolYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();
    }

    public function storeWriteWindow(StoreGroupWriteWindowRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        GroupWriteWindow::query()->create([
            'group_id' => $request->integer('group_id'),
            'reason' => $request->validated('reason'),
            'until_on' => $request->validated('until_on'),
            'enabled_by_id' => $request->user()->id,
        ]);
        $this->calendar->forget();

        return response()->json(['data' => $this->payload($schoolYear)], 201);
    }

    public function endWriteWindow(Request $request, GroupWriteWindow $window): JsonResponse
    {
        $this->authorize('viewAny', SchoolYear::class);
        $year = $this->calendar->current($request->user()->school_id);
        $this->authorize('manageWriteWindows', $year);

        if ($window->isActive()) {
            $window->update(['ended_at' => now(), 'ended_by_id' => $request->user()->id]);
            $this->calendar->forget();
        }

        return response()->json(['data' => $this->payload($year)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(SchoolYear $year): array
    {
        $year->refresh()->load(['closedBy:id,name', 'reopenedBy:id,name']);
        $today = SchoolTime::today()->toDateString();
        $previous = SchoolYear::query()->where('school_id', $year->school_id)
            ->where('year', '<', $year->year)->orderByDesc('year')->first();
        $hasLater = SchoolYear::query()->where('school_id', $year->school_id)
            ->where('year', '>', $year->year)->exists();

        return [
            'year' => [
                'id' => $year->id,
                'year' => $year->year,
                'status' => $year->status->value,
                'closing_enabled_from' => $year->closing_enabled_from?->toDateString(),
                'can_close' => $year->isOpen()
                    && $year->closing_enabled_from !== null
                    && $year->closing_enabled_from->toDateString() <= $today,
                'closed_at' => $year->closed_at?->toIso8601String(),
                'closed_by_name' => $year->closedBy?->name,
                // «Deshacer el cierre»: only while no later year exists.
                'can_reopen' => $year->isClosed() && ! $hasLater,
                'reopened_at' => $year->reopened_at?->toIso8601String(),
                'reopened_by_name' => $year->reopenedBy?->name,
            ],
            'next_year' => $year->year + 1,
            'previous' => $previous ? [
                'year' => $previous->year,
                'status' => $previous->status->value,
                'closed_at' => $previous->closed_at?->toIso8601String(),
            ] : null,
            'active_groups' => Group::query()->where('school_id', $year->school_id)
                ->where('school_year', $year->year)->count(),
            // Until the PTP and «Lo que pasó al año nuevo» exist (screen 06),
            // closing counts only the accommodations in force today: they
            // carry over by themselves because they live on the student.
            'carried_accommodations' => Accommodation::query()
                ->where('accommodations.school_id', $year->school_id)
                ->inForceOn($today)
                ->count(),
            'calendars' => $this->calendarRows($year, $today),
            'split_bodies' => array_values($year->split_calendar_bodies ?? []),
            // Still listed once the next year is open: loading can be enabled
            // in a group of a closed year at any time (e.g. «Exámenes de
            // febrero» after 2027 was opened).
            'closed_year_groups' => $this->closedYearGroups((int) $year->school_id),
            'write_windows' => $this->writeWindows($year),
        ];
    }

    /**
     * The year's period rows (PeriodCalendars): a body row carries its body
     * and the school's programs in it; a program row, its program. A period
     * lasts until the row's next one starts; the one in progress is the last
     * that has started (only while the year is open).
     *
     * @return list<array<string, mixed>>
     */
    protected function calendarRows(SchoolYear $year, string $today): array
    {
        return $this->calendars->rows($year)->map(function (array $row) use ($year, $today): array {
            /** @var Collection<int, SchoolPeriod> $own */
            $own = $row['periods']->values();
            $currentIndex = $year->isOpen()
                ? $own->filter(fn (SchoolPeriod $period) => $period->starts_on->toDateString() <= $today)->keys()->last()
                : null;
            $isBodyRow = $row['framework'] === null;

            return [
                'key' => $row['key'],
                'body' => $row['body']?->value,
                'curricular_framework_id' => $row['framework']?->id,
                'name' => $row['framework']?->name,
                'programs' => $row['programs']->map(fn (CurricularFramework $framework) => $framework->name)->values()->all(),
                'can_split' => $isBodyRow && $year->isOpen() && $row['body']->canSplitByProgram()
                    && $row['programs']->count() >= 2,
                'periods' => $own->map(fn (SchoolPeriod $period, int $index) => [
                    'id' => $period->id,
                    'name' => $period->name,
                    'starts_on' => $period->starts_on->toDateString(),
                    'state' => match (true) {
                        $index === $currentIndex => 'current',
                        $period->starts_on->toDateString() > $today => 'upcoming',
                        default => 'past',
                    },
                ])->all(),
            ];
        })->all();
    }

    /**
     * The groups of every closed year (up to the last one closed, the same
     * rule as SchoolYearCalendar::isInClosedYear), newest year first.
     *
     * @return list<array{id: int, name: string, school_year: int}>
     */
    protected function closedYearGroups(int $schoolId): array
    {
        $lastClosed = $this->calendar->lastClosedYear($schoolId);
        if ($lastClosed === null) {
            return [];
        }

        return Group::query()->where('school_id', $schoolId)
            ->whereNotNull('school_year')
            ->where('school_year', '<=', $lastClosed)
            ->orderByDesc('school_year')
            ->orderBy('name')
            ->get(['id', 'name', 'school_year'])
            ->map(fn (Group $group) => ['id' => $group->id, 'name' => $group->name, 'school_year' => (int) $group->school_year])
            ->all();
    }

    /**
     * Every window ever enabled in a group of a closed year (who, when, why,
     * until when), newest first.
     *
     * @return list<array<string, mixed>>
     */
    protected function writeWindows(SchoolYear $year): array
    {
        return GroupWriteWindow::query()->where('school_id', $year->school_id)
            ->with(['group:id,name,school_year', 'enabledBy:id,name', 'endedBy:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (GroupWriteWindow $window) => [
                'id' => $window->id,
                'group' => $window->group ? ['id' => $window->group->id, 'name' => $window->group->name, 'school_year' => (int) $window->group->school_year] : null,
                'reason' => $window->reason,
                'until_on' => $window->until_on->toDateString(),
                'enabled_by_name' => $window->enabledBy?->name,
                'enabled_at' => $window->created_at?->toIso8601String(),
                'ended_at' => $window->ended_at?->toIso8601String(),
                'ended_by_name' => $window->endedBy?->name,
                'active' => $window->isActive(),
            ])
            ->all();
    }
}
