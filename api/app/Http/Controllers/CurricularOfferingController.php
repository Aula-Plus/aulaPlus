<?php

namespace App\Http\Controllers;

use App\Http\Requests\CurricularStructure\UpdateCurricularOfferingRequest;
use App\Models\CurricularFramework;
use App\Models\CurricularOffering;
use App\Models\Group;
use App\Models\Subject;
use App\Services\CurricularStructure\CurricularOfferings;
use App\Services\CurricularStructure\PlannerFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Screen 23 › «Oferta curricular» (ClickUp 1dfd3mc4bgf): one row per
 * group-subject with the programs it follows. The rows are the subjects
 * taught in each group (group_teacher, set in Materias) plus any group-subject
 * that already has an offering. Direction only (CurricularOfferingPolicy).
 */
class CurricularOfferingController extends Controller
{
    protected CurricularOfferings $offerings;

    public function index(CurricularOfferings $offerings): JsonResponse
    {
        $this->authorize('manage', CurricularOffering::class);

        $this->offerings = $offerings;

        $stored = CurricularOffering::query()->with('curricularFramework')->orderBy('id')->get()
            ->groupBy(fn (CurricularOffering $offering) => $offering->group_id.'-'.$offering->subject_id);

        // Group (tenant-scoped) constrains the pivot rows to this school.
        $taught = DB::table('group_teacher')
            ->whereIn('group_id', Group::query()->select('id'))
            ->select(['group_id', 'subject_id'])
            ->distinct()
            ->get()
            ->map(fn ($row) => $row->group_id.'-'.$row->subject_id);

        $pairs = $taught->concat($stored->keys())->unique()
            ->map(fn (string $pair) => array_map('intval', explode('-', $pair)));

        $groups = Group::query()->whereKey($pairs->pluck(0)->all())->get()->keyBy('id');
        $subjects = Subject::query()->whereKey($pairs->pluck(1)->all())->get()->keyBy('id');

        $rows = $pairs
            ->filter(fn (array $pair) => $groups->has($pair[0]) && $subjects->has($pair[1]))
            ->map(fn (array $pair) => $this->row(
                $groups[$pair[0]],
                $subjects[$pair[1]],
            ))
            ->sortBy(fn (array $row) => [$row['group']['school_year'] * -1, $row['group']['name'], $row['subject']['name']])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function update(
        UpdateCurricularOfferingRequest $request,
        Group $group,
        Subject $subject,
        CurricularOfferings $offerings,
    ): JsonResponse {
        $this->offerings = $offerings;
        // The mandatory programs of the group's level are always kept.
        $ids = collect($request->validated('curricular_framework_ids'))->map(fn ($id) => (int) $id)
            ->concat($offerings->mandatoryFor($group)->pluck('id'))
            ->unique();

        DB::transaction(function () use ($group, $subject, $ids): void {
            $current = CurricularOffering::query()->forGroupSubject($group->id, $subject->id)->get();

            // Deleted one by one (not a bulk delete) so each removal is audited.
            $current->reject(fn (CurricularOffering $offering) => $ids->contains($offering->curricular_framework_id))
                ->each->delete();

            $ids->diff($current->pluck('curricular_framework_id'))
                ->each(fn (int $id) => CurricularOffering::create([
                    'group_id' => $group->id,
                    'subject_id' => $subject->id,
                    'curricular_framework_id' => $id,
                ]));
        });

        return response()->json(['data' => $this->row($group, $subject)]);
    }

    /**
     * What a unit of this group-subject shows in its planner (screen 13):
     * its programs and the resolved fields.
     */
    public function planner(Group $group, Subject $subject, PlannerFields $fields): JsonResponse
    {
        $this->authorize('viewPlanner', [CurricularOffering::class, $group, $subject]);

        return response()->json(['data' => $fields->forGroupSubject($group, $subject)]);
    }

    /**
     * A row: its effective programs (stored + mandatory, the mandatory ones
     * flagged `locked`) and the programs its editor may offer.
     *
     * @return array<string, mixed>
     */
    protected function row(Group $group, Subject $subject): array
    {
        $group->loadMissing('educationLevel');
        $mandatory = $this->offerings->mandatoryFor($group)->pluck('id');

        return [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'school_year' => (int) $group->school_year,
                'level' => $group->educationLevel?->label,
            ],
            'subject' => ['id' => $subject->id, 'name' => $subject->name],
            'frameworks' => $this->offerings->effectiveFor($group, $subject)
                ->map(fn (CurricularFramework $framework) => $this->framework($framework, $mandatory))
                ->values()->all(),
            'available_frameworks' => $this->offerings->eligibleFor($group)
                ->map(fn (CurricularFramework $framework) => $this->framework($framework, $mandatory))
                ->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $mandatory
     * @return array<string, mixed>
     */
    protected function framework(CurricularFramework $framework, Collection $mandatory): array
    {
        return [
            'id' => $framework->id,
            'name' => $framework->name,
            'planner_family' => $framework->planner_family?->value,
            'locked' => $mandatory->contains($framework->id),
        ];
    }
}
