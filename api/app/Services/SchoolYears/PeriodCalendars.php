<?php

namespace App\Services\SchoolYears;

use App\Enums\CalendarBody;
use App\Models\CurricularFramework;
use App\Models\School;
use App\Models\SchoolPeriod;
use App\Models\SchoolYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rows of screen 26 › Períodos (ClickUp 1dfd3mc4bh7, Eitán 17/11): one row
 * per body the school has in its curricular offer — ANEP Bachillerato, ANEP
 * Ciclo Básico y Primaria, Cambridge, IB (CalendarBody) — and, once direction
 * splits Cambridge or IB by program («Separar por programa»), one row per
 * program of that body instead. A program with no body is its own row.
 *
 * A row's periods are stored on the body (`school_periods.calendar_body`) or
 * on the program (`curricular_framework_id`). Splitting copies the body's
 * periods to each program; joining back is only allowed while every program
 * still has the same periods, so nothing anyone loaded is lost.
 */
class PeriodCalendars
{
    /**
     * The school's active programs (its curricular offer).
     *
     * @return Collection<int, CurricularFramework>
     */
    public function activePrograms(int $schoolId): Collection
    {
        return School::query()->findOrFail($schoolId)
            ->curricularFrameworks()->wherePivot('active', true)
            ->orderBy('curricular_frameworks.name')
            ->get();
    }

    /**
     * The year's rows, each with its periods in date order. Rows of the
     * school's active programs plus any row that has periods this year (a
     * program deactivated later keeps showing what was set).
     *
     * @return Collection<int, array{key: string, body: CalendarBody|null, framework: CurricularFramework|null, programs: Collection<int, CurricularFramework>, periods: Collection<int, SchoolPeriod>}>
     */
    public function rows(SchoolYear $year): Collection
    {
        $active = $this->activePrograms((int) $year->school_id);
        $periods = $year->periods()->with('curricularFramework')
            ->orderBy('starts_on')->orderBy('id')->get();

        /** @var array<string, array{key: string, body: CalendarBody|null, framework: CurricularFramework|null, programs: Collection<int, CurricularFramework>, periods: Collection<int, SchoolPeriod>}> $rows */
        $rows = [];
        $add = function (?CalendarBody $body, ?CurricularFramework $framework) use (&$rows): string {
            $key = $framework !== null ? 'program:'.$framework->id : 'body:'.$body->value;
            $rows[$key] ??= [
                'key' => $key,
                'body' => $framework !== null ? $framework->calendar_body : $body,
                'framework' => $framework,
                'programs' => collect(),
                'periods' => collect(),
            ];

            return $key;
        };

        foreach ($active as $framework) {
            $key = $this->rowIsPerProgram($year, $framework)
                ? $add(null, $framework)
                : $add($framework->calendar_body, null);
            $rows[$key]['programs']->push($framework);
        }

        foreach ($periods as $period) {
            $key = $period->calendar_body !== null
                ? $add($period->calendar_body, null)
                : $add(null, $period->curricularFramework);
            $rows[$key]['periods']->push($period);
        }

        $order = array_map(fn (CalendarBody $body) => $body->value, CalendarBody::cases());

        return collect($rows)
            ->sortBy(fn (array $row) => [
                $row['body'] !== null ? array_search($row['body']->value, $order, true) : count($order),
                $row['framework']?->name ?? '',
            ])
            ->values();
    }

    /** Whether the program's periods are its own (no body, or its body split). */
    public function rowIsPerProgram(SchoolYear $year, CurricularFramework $framework): bool
    {
        $body = $framework->calendar_body;

        return $body === null || ($body->canSplitByProgram() && $year->isSplit($body));
    }

    /**
     * The school's active programs of a body.
     *
     * @return Collection<int, CurricularFramework>
     */
    public function activeProgramsOf(SchoolYear $year, CalendarBody $body): Collection
    {
        return $this->activePrograms((int) $year->school_id)
            ->filter(fn (CurricularFramework $framework) => $framework->calendar_body === $body)
            ->values();
    }

    /**
     * «Separar por programa»: each active program of the body starts with a
     * copy of the body's periods, and from then on has its own.
     */
    public function split(SchoolYear $year, CalendarBody $body): void
    {
        DB::transaction(function () use ($year, $body): void {
            // A double submit must not copy the periods twice: lock the year
            // and re-check under the lock.
            $year = SchoolYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($year->isSplit($body)) {
                return;
            }
            $programs = $this->activeProgramsOf($year, $body);
            $shared = $year->periods()->where('calendar_body', $body->value)->get();

            foreach ($programs as $framework) {
                foreach ($shared as $period) {
                    $year->periods()->forceCreate([
                        'school_id' => $year->school_id,
                        'curricular_framework_id' => $framework->id,
                        'name' => $period->name,
                        'starts_on' => $period->starts_on->toDateString(),
                    ]);
                }
            }
            // One by one, so each removal is audited.
            $shared->each->delete();

            $year->update(['split_calendar_bodies' => collect($year->split_calendar_bodies ?? [])
                ->push($body->value)->unique()->values()->all()]);
        });
    }

    /**
     * The periods each program of a split body has, as comparable lists of
     * [name, starts_on] — only the programs that have any.
     *
     * @return Collection<int, list<array{0: string, 1: string}>>
     */
    public function programSchemes(SchoolYear $year, CalendarBody $body): Collection
    {
        return $this->programPeriods($year, $body)
            ->groupBy('curricular_framework_id')
            ->map(fn (Collection $own) => $own->sortBy(fn (SchoolPeriod $period) => [$period->starts_on->toDateString(), $period->name])
                ->map(fn (SchoolPeriod $period) => [$period->name, $period->starts_on->toDateString()])
                ->values()->all())
            ->values();
    }

    /**
     * Back to one row for the body. Only called when every program that has
     * periods has the same ones (see JoinCalendarBodyRequest): those become
     * the body's.
     */
    public function join(SchoolYear $year, CalendarBody $body): void
    {
        DB::transaction(function () use ($year, $body): void {
            $year = SchoolYear::query()->lockForUpdate()->findOrFail($year->id);
            if (! $year->isSplit($body)) {
                return;
            }
            foreach ($this->programSchemes($year, $body)->first() ?? [] as [$name, $startsOn]) {
                $year->periods()->forceCreate([
                    'school_id' => $year->school_id,
                    'calendar_body' => $body,
                    'name' => $name,
                    'starts_on' => $startsOn,
                ]);
            }
            $this->programPeriods($year, $body)->each->delete();

            $year->update(['split_calendar_bodies' => collect($year->split_calendar_bodies ?? [])
                ->reject(fn (string $value) => $value === $body->value)->values()->all()]);
        });
    }

    /**
     * @return Collection<int, SchoolPeriod>
     */
    private function programPeriods(SchoolYear $year, CalendarBody $body): Collection
    {
        return $year->periods()
            ->whereNotNull('curricular_framework_id')
            ->whereIn('curricular_framework_id', CurricularFramework::query()
                ->where('calendar_body', $body->value)->select('id'))
            ->get();
    }
}
