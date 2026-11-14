<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnualPlans\StoreUnitRequest;
use App\Http\Requests\AnnualPlans\UpdateUnitRequest;
use App\Models\AnnualPlan;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Units of an annual plan (screen 12, «Unidades»). Only the plan's owning
 * teacher writes; the unit's status is never stored, it is derived by
 * App\Services\AnnualPlans\AnnualPlanProgress.
 */
class UnitController extends Controller
{
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
