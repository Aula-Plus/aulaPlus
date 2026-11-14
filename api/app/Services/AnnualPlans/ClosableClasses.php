<?php

namespace App\Services\AnnualPlans;

use App\Enums\ClassSessionStatus;
use App\Models\AnnualPlan;
use App\Models\ClassSession;
use App\Models\Unit;
use App\Models\UnitObjective;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The classes where «Lo terminé» may record an objective as «todo» (screen
 * 12, ClickUp 1dfd3mc4bg8, PO 14/11): classes of the plan's group and
 * subject. A class counts as of the plan's subject when it carries that
 * subject, or, without a subject of its own, when it hangs from a unit of a
 * plan of that same group and subject (or from no unit at all).
 *
 * Every query here goes through ClassSession's SchoolScope.
 */
class ClosableClasses
{
    /** How many recent classes the picker shows before searching. */
    public const RECENT_LIMIT = 5;

    /** How many classes a search returns at most. */
    public const SEARCH_LIMIT = 20;

    /**
     * Classes of the objective's plan group and subject, any status or date.
     *
     * @return Builder<ClassSession>
     */
    public function eligible(UnitObjective $objective): Builder
    {
        /** @var AnnualPlan $plan */
        $plan = $objective->unit->annualPlan;

        $sameSubjectUnits = Unit::query()
            ->whereHas('annualPlan', fn (Builder $q) => $q
                ->where('group_id', $plan->group_id)
                ->where('subject_id', $plan->subject_id))
            ->select('units.id');

        return ClassSession::query()
            ->where('group_id', $plan->group_id)
            ->where(fn (Builder $q) => $q
                ->where('subject_id', $plan->subject_id)
                ->orWhere(fn (Builder $q) => $q
                    ->whereNull('subject_id')
                    ->where(fn (Builder $q) => $q
                        ->whereNull('unit_id')
                        ->orWhereIn('unit_id', $sameSubjectUnits))));
    }

    /**
     * What the picker lists: eligible, non-cancelled classes up to today,
     * latest first. Without a search, the most recent few; with one, those
     * whose title contains it (case-insensitive) or whose date is it.
     *
     * @return Collection<int, array{id: int, date: string|null, title: string|null, coverage: string|null}>
     */
    public function forPicker(UnitObjective $objective, ?string $search = null): Collection
    {
        $query = $this->eligible($objective)
            ->where('status', '!=', ClassSessionStatus::Cancelled->value)
            ->whereDate('date', '<=', now()->toDateString())
            ->with(['unitObjectives' => fn ($q) => $q->whereKey($objective->getKey())])
            ->orderByDesc('date')
            ->orderByDesc('id');

        $search = trim((string) $search);
        if ($search !== '') {
            $needle = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereDate('date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $search) ? $search : '0001-01-01'));
            $query->limit(self::SEARCH_LIMIT);
        } else {
            $query->limit(self::RECENT_LIMIT);
        }

        return $query->get(['id', 'date', 'title'])->map(fn (ClassSession $class): array => [
            'id' => $class->id,
            'date' => $class->date?->toDateString(),
            'title' => $class->title,
            // What this class's closing recorded for the objective (full /
            // partial / none), or null when it does not work it yet.
            'coverage' => $class->unitObjectives->first()?->pivot?->coverage,
        ])->values();
    }
}
