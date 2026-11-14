<?php

namespace App\Services\CurricularStructure;

use App\Models\CurricularFramework;
use App\Models\CurricularOffering;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Which programs a group-subject follows («Oferta curricular», screen 23;
 * PO answers of 13/11 in ClickUp 1dfd3mc4bgf):
 *
 * - Eligible: the programs the school has active (Aula+ activates them at the
 *   school's request, P23-DEC-01) that apply to the group's level. A group
 *   without a level gets every active program.
 * - Mandatory: the school's programs flagged `mandatory` (the regime Aula+
 *   loads for it — ANEP today, for every school in Uruguay) that apply to the
 *   group's level. They are always part of the offering and cannot be
 *   removed. It is data per school, never a fixed rule in code, and never a
 *   text shown to users.
 * - Effective: what was stored for the group-subject, while still eligible,
 *   plus the mandatory ones.
 *   It decides both what the AI receives and the scale results are recorded
 *   with (PO 13/11).
 */
class CurricularOfferings
{
    /** @var array<int, Collection<int, CurricularFramework>> */
    protected array $activeBySchool = [];

    /** @return Collection<int, CurricularFramework> */
    public function eligibleFor(Group $group): Collection
    {
        $level = $this->levelKey($group);

        return $this->active($group->school_id)
            ->filter(fn (CurricularFramework $framework) => $framework->appliesToLevel($level))
            ->values();
    }

    /** @return Collection<int, CurricularFramework> */
    public function mandatoryFor(Group $group): Collection
    {
        if ($this->levelKey($group) === null) {
            return collect();
        }

        return $this->eligibleFor($group)
            ->filter(fn (CurricularFramework $framework) => (bool) $framework->pivot->mandatory)
            ->values();
    }

    /**
     * Whether the school has a mandatory regime at all (a group without a
     * level must still pick one of those programs).
     *
     * @return Collection<int, CurricularFramework>
     */
    public function mandatoryForSchool(int $schoolId): Collection
    {
        return $this->active($schoolId)
            ->filter(fn (CurricularFramework $framework) => (bool) $framework->pivot->mandatory)
            ->values();
    }

    /**
     * The stored programs, kept only while they are still eligible (active
     * for the school and for the group's level), plus the mandatory ones. A
     * program Aula+ deactivates, or one that stops applying because the group
     * changed level, stays stored but no longer reaches the AI, the planner
     * or the scale, and the editor can save the row again without it.
     *
     * @param  Collection<int, CurricularFramework>|null  $stored  the row's stored programs, when the caller already loaded them
     * @return Collection<int, CurricularFramework>
     */
    public function effectiveFor(Group $group, Subject $subject, ?Collection $stored = null): Collection
    {
        $stored ??= CurricularFramework::query()
            ->whereIn('id', CurricularOffering::query()
                ->forGroupSubject($group->id, $subject->id)
                ->select('curricular_framework_id'))
            ->get();

        $eligible = $this->eligibleFor($group)->pluck('id');

        return $this->mandatoryFor($group)
            ->concat($stored->filter(fn (CurricularFramework $framework) => $eligible->contains($framework->id)))
            ->unique('id')
            ->sortBy('id')
            ->values();
    }

    /** @return Collection<int, int> */
    public function effectiveIdsFor(Group $group, Subject $subject): Collection
    {
        return $this->effectiveFor($group, $subject)->pluck('id');
    }

    protected function levelKey(Group $group): ?string
    {
        $group->loadMissing('educationLevel');

        return $group->educationLevel?->key;
    }

    /** @return Collection<int, CurricularFramework> */
    protected function active(int $schoolId): Collection
    {
        return $this->activeBySchool[$schoolId] ??= School::query()->findOrFail($schoolId)
            ->curricularFrameworks()
            ->wherePivot('active', true)
            ->orderBy('curricular_frameworks.name')
            ->get();
    }
}
