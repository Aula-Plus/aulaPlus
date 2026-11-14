<?php

namespace App\Services\AnnualPlans;

use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Enums\UnitStatus;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\Unit;
use App\Models\UnitObjective;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Derives what screen 12 (Programa anual) shows from what the teacher actually
 * did, never from a hand-picked status (P12-DEC-03, P12-DEC-06):
 *
 * - an objective is *planned* when a (non-cancelled) class or an assessment
 *   points at it, and *given* when a class closed covering it fully or an
 *   assessment with results worked it;
 * - an objective is *started* («empezado») when it is not given but a
 *   (non-cancelled) class closed covering it in part. Started never counts as
 *   given (P12-UNI-01, P12-COB-02); it is only shown, with the class where it
 *   was last recorded in part so the teacher can correct that record
 *   («Lo terminé», ClickUp answer of 10/11);
 * - a unit is `planned` when all its objectives are planned and `given` when
 *   all are given; a unit without objectives has no status (`null`) — the
 *   screen asks the teacher to write them instead;
 * - coverage is plain counters of what already happened — no expected pace.
 */
class AnnualPlanProgress
{
    /**
     * @return array{units: list<array<string, mixed>>, coverage: array<string, int>, marks: list<array<string, mixed>>}
     */
    public function for(AnnualPlan $plan): array
    {
        $units = $plan->units()
            ->orderBy('position')
            ->orderBy('id')
            ->with([
                'objectives' => fn ($query) => $this->withProgressFlags($query),
                'objectives.classSessions' => fn ($query) => $query
                    ->where('class_sessions.status', '!=', ClassSessionStatus::Cancelled->value)
                    ->where('class_session_unit_objective.coverage', ObjectiveCoverage::Partial->value)
                    ->orderByDesc('class_sessions.date')
                    ->orderByDesc('class_sessions.id')
                    ->select(['class_sessions.id', 'class_sessions.date', 'class_sessions.title']),
            ])
            ->get();

        $unitRows = $units->map(fn (Unit $unit): array => $this->unitRow($unit))->values();

        $objectives = $units->flatMap(fn (Unit $unit) => $unit->objectives);
        $plannedCount = $objectives->filter(fn (UnitObjective $o) => $this->isPlanned($o))->count();

        return [
            'units' => $unitRows->all(),
            'coverage' => [
                'units_total' => $units->count(),
                'units_given' => $unitRows->where('status', UnitStatus::Given->value)->count(),
                'objectives_total' => $objectives->count(),
                'objectives_planned' => $plannedCount,
                'objectives_given' => $objectives->filter(fn (UnitObjective $o) => $this->isGiven($o))->count(),
                'objectives_unplanned' => $objectives->count() - $plannedCount,
            ],
            'marks' => $this->marks($plan, $units)->all(),
        ];
    }

    /**
     * @param  Builder<UnitObjective>|HasMany<UnitObjective, Unit>  $query
     */
    protected function withProgressFlags($query)
    {
        return $query->withExists([
            'classSessions as has_class' => fn (Builder $q) => $q->where('class_sessions.status', '!=', ClassSessionStatus::Cancelled->value),
            'assessments as has_assessment',
            // A cancelled class never counts, not even if it was closed «todo»
            // before being cancelled (ClickUp 1dfd3mc4bg8, PO 12/11).
            'classSessions as given_by_class' => fn (Builder $q) => $q
                ->where('class_sessions.status', '!=', ClassSessionStatus::Cancelled->value)
                ->where('class_session_unit_objective.coverage', ObjectiveCoverage::Full->value),
            'assessments as given_by_assessment' => fn (Builder $q) => $q->whereHas('results'),
        ]);
    }

    protected function isPlanned(UnitObjective $objective): bool
    {
        return (bool) $objective->has_class || (bool) $objective->has_assessment;
    }

    protected function isGiven(UnitObjective $objective): bool
    {
        return (bool) $objective->given_by_class || (bool) $objective->given_by_assessment;
    }

    /**
     * Not given, but some class recorded it «una parte». The eager-loaded
     * `classSessions` relation holds only those partial, non-cancelled
     * classes, latest first.
     */
    protected function isStarted(UnitObjective $objective): bool
    {
        return ! $this->isGiven($objective) && $objective->classSessions->isNotEmpty();
    }

    protected function objectiveStatus(UnitObjective $objective): string
    {
        return match (true) {
            $this->isGiven($objective) => 'given',
            $this->isStarted($objective) => 'started',
            $this->isPlanned($objective) => 'planned',
            default => 'unplanned',
        };
    }

    /**
     * The class whose closing «Lo terminé» opens: the latest class where the
     * objective was recorded «una parte». Date and title only.
     *
     * @return array{id: int, date: string|null, title: string|null}|null
     */
    protected function classToClose(UnitObjective $objective): ?array
    {
        if (! $this->isStarted($objective)) {
            return null;
        }
        /** @var ClassSession $class */
        $class = $objective->classSessions->first();

        return [
            'id' => $class->id,
            'date' => $class->date?->toDateString(),
            'title' => $class->title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function unitRow(Unit $unit): array
    {
        $objectives = $unit->objectives;
        $total = $objectives->count();
        $planned = $objectives->filter(fn (UnitObjective $o) => $this->isPlanned($o))->count();
        $given = $objectives->filter(fn (UnitObjective $o) => $this->isGiven($o))->count();
        $started = $objectives->filter(fn (UnitObjective $o) => $this->isStarted($o))->count();

        $status = match (true) {
            $total === 0 => null,
            $given === $total => UnitStatus::Given,
            $planned === $total => UnitStatus::Planned,
            default => UnitStatus::Unplanned,
        };

        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'position' => $unit->position,
            'start_date' => $unit->start_date?->toDateString(),
            'end_date' => $unit->end_date?->toDateString(),
            'status' => $status?->value,
            'objectives_count' => $total,
            'planned_count' => $planned,
            'given_count' => $given,
            'started_count' => $started,
            'objectives' => $objectives->map(fn (UnitObjective $o): array => [
                'id' => $o->id,
                'description' => $o->description,
                'position' => $o->position,
                'planned' => $this->isPlanned($o),
                'given' => $this->isGiven($o),
                'status' => $this->objectiveStatus($o),
                'class_to_close' => $this->classToClose($o),
            ])->values()->all(),
        ];
    }

    /**
     * Marks on the timeline: the plan's classes and the assessments of the
     * same group and subject in the plan's year. Titles only — never students.
     *
     * @param  Collection<int, Unit>  $units
     * @return Collection<int, array<string, mixed>>
     */
    protected function marks(AnnualPlan $plan, Collection $units): Collection
    {
        $classes = ClassSession::query()
            ->whereIn('unit_id', $units->pluck('id'))
            ->where('status', '!=', ClassSessionStatus::Cancelled->value)
            ->get(['id', 'unit_id', 'date', 'title'])
            ->map(fn (ClassSession $c): array => [
                'kind' => 'class',
                'id' => $c->id,
                'date' => $c->date?->toDateString(),
                'title' => $c->title,
                'unit_id' => $c->unit_id,
            ]);

        $assessments = Assessment::query()
            ->where('group_id', $plan->group_id)
            ->where('subject_id', $plan->subject_id)
            ->whereYear('administered_at', $plan->year)
            ->get(['id', 'type', 'purpose', 'administered_at'])
            ->map(fn (Assessment $a): array => [
                'kind' => 'assessment',
                'id' => $a->id,
                'date' => $a->administered_at?->toDateString(),
                'title' => $a->purpose ?: null,
                'unit_id' => null,
            ]);

        return $classes->concat($assessments)->sortBy('date')->values();
    }
}
