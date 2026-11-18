<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnualPlans\SyncObjectiveCurricularItemsRequest;
use App\Http\Requests\AnnualPlans\UnitObjectiveRequest;
use App\Models\CurricularItem;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Services\AnnualPlans\ClosableClasses;
use App\Services\Units\UnitCurriculum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Objectives of a unit. The teacher writes them (P13-OBJ-05); whether each is
 * planned or given is derived from the classes and assessments that point at
 * it, never set here.
 */
class UnitObjectiveController extends Controller
{
    public function store(UnitObjectiveRequest $request, Unit $unit): JsonResponse
    {
        $objective = $unit->objectives()->create([
            'description' => $request->validated('description'),
            'position' => ((int) $unit->objectives()->max('position')) + 1,
        ]);

        return response()->json(['data' => $this->row($objective)], 201);
    }

    public function update(UnitObjectiveRequest $request, UnitObjective $objective): JsonResponse
    {
        $objective->update(['description' => $request->validated('description')]);

        return response()->json(['data' => $this->row($objective)]);
    }

    public function destroy(UnitObjective $objective): Response
    {
        $this->authorize('delete', $objective->unit);

        $objective->delete();

        return response()->noContent();
    }

    /**
     * PUT /unit-objectives/{objective}/curricular-items — the curricular items
     * this objective works, chosen from the program catalog (P13-DEC-05).
     */
    public function syncCurricularItems(SyncObjectiveCurricularItemsRequest $request, UnitObjective $objective, UnitCurriculum $curriculum): JsonResponse
    {
        $objective->curricularItems()->sync(collect($request->validated('curricular_item_ids'))->map(fn ($id) => (int) $id)->all());

        $items = $objective->curricularItems()->with('curricularCatalog.curricularFramework:id,name')->get();

        return response()->json(['data' => [
            ...$this->row($objective),
            'curricular_items' => $items->map(fn (CurricularItem $item) => $curriculum->row($item))->values()->all(),
        ]]);
    }

    /**
     * The classes «Lo terminé» offers for this objective: the recent ones of
     * the plan's group and subject, or those matching `search` (title or
     * YYYY-MM-DD date), each with what its closing recorded for the objective.
     * Only the plan's owner uses «Lo terminé».
     */
    public function closableClasses(Request $request, UnitObjective $objective, ClosableClasses $classes): JsonResponse
    {
        $this->authorize('update', $objective->unit);

        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $classes->forPicker($objective, $validated['search'] ?? null)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(UnitObjective $objective): array
    {
        return [
            'id' => $objective->id,
            'unit_id' => $objective->unit_id,
            'description' => $objective->description,
            'position' => $objective->position,
        ];
    }
}
