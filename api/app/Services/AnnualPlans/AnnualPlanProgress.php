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
 * - a unit is `planned` when all its objectives are planned and `given` when
 *   all are given; a unit without objectives is `unplanned`;
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
            ->with(['objectives' => fn ($query) => $this->withProgressFlags($query)])
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
            'classSessions as given_by_class' => fn (Builder $q) => $q->where('class_session_unit_objective.coverage', ObjectiveCoverage::Full->value),
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
     * @return array<string, mixed>
     */
    protected function unitRow(Unit $unit): array
    {
        $objectives = $unit->objectives;
        $total = $objectives->count();
        $planned = $objectives->filter(fn (UnitObjective $o) => $this->isPlanned($o))->count();
        $given = $objectives->filter(fn (UnitObjective $o) => $this->isGiven($o))->count();

        $status = match (true) {
            $total > 0 && $given === $total => UnitStatus::Given,
            $total > 0 && $planned === $total => UnitStatus::Planned,
            default => UnitStatus::Unplanned,
        };

        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'position' => $unit->position,
            'start_date' => $unit->start_date?->toDateString(),
            'end_date' => $unit->end_date?->toDateString(),
            'status' => $status->value,
            'objectives_count' => $total,
            'planned_count' => $planned,
            'given_count' => $given,
            'objectives' => $objectives->map(fn (UnitObjective $o): array => [
                'id' => $o->id,
                'description' => $o->description,
                'position' => $o->position,
                'planned' => $this->isPlanned($o),
                'given' => $this->isGiven($o),
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
