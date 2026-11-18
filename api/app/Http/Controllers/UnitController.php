<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnualPlans\StoreUnitRequest;
use App\Http\Requests\AnnualPlans\UpdateUnitPlannerRequest;
use App\Http\Requests\AnnualPlans\UpdateUnitRequest;
use App\Models\Accommodation;
use App\Models\AnnualPlan;
use App\Models\CurricularItem;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Services\AnnualPlans\AnnualPlanProgress;
use App\Services\Units\UnitCurriculum;
use App\Services\Units\UnitPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Units of an annual plan (screen 12, «Unidades») and the unit screen itself
 * (screen 13, «Unidad», ClickUp 1dfd3mc4bga). Only the plan's owning teacher
 * writes; Psicopedagogía and Dirección read (UnitPolicy). The unit's status is
 * never stored, it is derived by App\Services\AnnualPlans\AnnualPlanProgress.
 */
class UnitController extends Controller
{
    /**
     * GET /units/{unit} — screen 13: the unit with its derived status, its
     * objectives (each with the curricular items it works) and its planner
     * with what the teacher filled in.
     */
    public function show(Request $request, Unit $unit, AnnualPlanProgress $progress, UnitPlanner $planner, UnitCurriculum $curriculum): JsonResponse
    {
        $this->authorize('view', $unit);

        $plan = $unit->annualPlan->loadMissing(['group', 'subject', 'teacher:id,name', 'curricularFramework:id,name']);
        $row = collect($progress->for($plan)['units'])->firstWhere('id', $unit->id);

        $items = UnitObjective::query()
            ->where('unit_id', $unit->id)
            ->with(['curricularItems.curricularCatalog.curricularFramework:id,name', 'curricularItems.parent:id,name'])
            ->get()
            ->mapWithKeys(fn (UnitObjective $objective) => [
                $objective->id => $objective->curricularItems
                    ->map(fn (CurricularItem $item) => $curriculum->row($item))
                    ->values()->all(),
            ]);

        $row['objectives'] = array_map(fn (array $objective): array => [
            ...$objective,
            'curricular_items' => $items->get($objective['id'], []),
        ], $row['objectives']);

        return response()->json(['data' => [
            ...$row,
            'plan' => [
                'id' => $plan->id,
                'year' => $plan->year,
                'group' => ['id' => $plan->group?->id, 'name' => $plan->group?->name],
                'subject' => ['id' => $plan->subject?->id, 'name' => $plan->subject?->name],
                'teacher' => ['id' => $plan->teacher?->id, 'name' => $plan->teacher?->name],
                'framework' => ['id' => $plan->curricularFramework?->id, 'name' => $plan->curricularFramework?->name],
            ],
            'planner' => $planner->withValues($unit),
            'can_edit' => $request->user()->can('update', $unit),
        ]]);
    }

    /**
     * PATCH /units/{unit}/planner — saves the planner fields sent; none is
     * mandatory (P13-DEC-07), an empty value clears the field.
     */
    public function updatePlanner(UpdateUnitPlannerRequest $request, Unit $unit, UnitPlanner $planner): JsonResponse
    {
        $values = $unit->planner_values ?? [];
        foreach ($request->normalizedValues() as $key => $value) {
            if ($value === null) {
                unset($values[$key]);
            } else {
                $values[$key] = $value;
            }
        }
        $unit->update(['planner_values' => $values === [] ? null : $values]);

        return response()->json(['data' => $planner->withValues($unit->refresh())]);
    }

    /**
     * GET /units/{unit}/accommodations — «Diferenciación» and the «A mano»
     * panel (screen 13): the effective accommodations of the plan's group,
     * by type, with the students of each type for «Ver el detalle por alumno»
     * (ClickUp answer of 17/11: the detail opens in the same panel, read-only
     * for Psicopedagogía and Dirección).
     *
     * Only what the viewer already sees in each student file is listed
     * (Accommodation::scopeVisibleTo: a teacher gets the general ones and those
     * of the subjects they teach in the group). Field-level minimum (rule 11):
     * the adjustment's name and category and the student's name — never its
     * description, barrier, origin report or diagnosis.
     */
    public function accommodations(Request $request, Unit $unit): JsonResponse
    {
        $this->authorize('view', $unit);

        $group = $unit->annualPlan->group;
        $students = $group?->students()->get(['students.id', 'students.full_name'])->keyBy('id') ?? collect();

        $rows = Accommodation::query()
            ->visibleTo($request->user())
            ->effective()
            ->whereIn('accommodations.student_id', $students->keys())
            ->orderBy('accommodations.id')
            ->get(['accommodations.id', 'accommodations.student_id', 'accommodations.type', 'accommodations.category'])
            ->groupBy('type')
            ->map(fn (Collection $byType, string $type): array => [
                'type' => $type,
                'category' => $byType->first()->category?->value,
                'student_count' => $byType->pluck('student_id')->unique()->count(),
                'students' => $byType->pluck('student_id')->unique()
                    ->map(fn (int $id) => ['id' => $id, 'full_name' => $students->get($id)?->full_name])
                    ->sortBy('full_name')
                    ->values()->all(),
            ])
            ->sortBy('type')
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /units/{unit}/curricular-items — what the objective picker offers:
     * the catalog of the plan's program (UnitCurriculum). Empty when no
     * catalog is loaded; the screen says so.
     */
    public function curricularItems(Unit $unit, UnitCurriculum $curriculum): JsonResponse
    {
        $this->authorize('view', $unit);

        $items = $curriculum->itemsFor($unit);
        $byId = $items->keyBy('id');

        return response()->json(['data' => $items
            ->map(fn (CurricularItem $item) => $curriculum->row($item, $byId))
            ->values()->all()]);
    }

    public function store(StoreUnitRequest $request, AnnualPlan $annualPlan): JsonResponse
    {
        $unit = $annualPlan->units()->create([
            ...$request->validated(),
            'position' => ((int) $annualPlan->units()->max('position')) + 1,
        ]);

        return response()->json(['data' => $this->row($unit)], 201);
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        $unit->update($request->validated());

        return response()->json(['data' => $this->row($unit->refresh())]);
    }

    public function destroy(Unit $unit): Response
    {
        $this->authorize('delete', $unit);

        $unit->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'annual_plan_id' => $unit->annual_plan_id,
            'name' => $unit->name,
            'position' => $unit->position,
            'start_date' => $unit->start_date?->toDateString(),
            'end_date' => $unit->end_date?->toDateString(),
        ];
    }
}
