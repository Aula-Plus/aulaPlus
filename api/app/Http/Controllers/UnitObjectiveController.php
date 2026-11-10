<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnualPlans\UnitObjectiveRequest;
use App\Models\Unit;
use App\Models\UnitObjective;
use Illuminate\Http\JsonResponse;
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
