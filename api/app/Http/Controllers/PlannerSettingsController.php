<?php

namespace App\Http\Controllers;

use App\Http\Requests\CurricularStructure\SchoolPlannerFieldRequest;
use App\Http\Requests\CurricularStructure\UpdatePlannerFieldRequest;
use App\Models\CurricularOffering;
use App\Models\PlannerFieldDefinition;
use App\Models\SchoolPlannerField;
use App\Services\CurricularStructure\PlannerFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Screen 23 › «Planificadores» (ClickUp 1dfd3mc4bgf): per framework, the
 * unit-planner fields with the school's names and visibility, and the
 * school's own fields. Direction only (CurricularOfferingPolicy::manage).
 * Changes apply to new units.
 */
class PlannerSettingsController extends Controller
{
    public function show(PlannerFields $fields): JsonResponse
    {
        $this->authorize('manage', CurricularOffering::class);

        return response()->json(['data' => $fields->settings()]);
    }

    public function updateField(UpdatePlannerFieldRequest $request, PlannerFieldDefinition $definition, PlannerFields $fields): JsonResponse
    {
        SchoolPlannerField::query()->updateOrCreate(
            ['planner_field_definition_id' => $definition->id],
            ['name' => $request->schoolName(), 'shown' => $request->boolean('shown')],
        );

        return response()->json(['data' => $fields->settings()]);
    }

    public function storeCustom(SchoolPlannerFieldRequest $request, PlannerFields $fields): JsonResponse
    {
        $position = (int) SchoolPlannerField::query()->whereNull('planner_field_definition_id')->max('position') + 1;

        SchoolPlannerField::create([...$request->attributesToSave(), 'position' => $position]);

        return response()->json(['data' => $fields->settings()], 201);
    }

    public function updateCustom(SchoolPlannerFieldRequest $request, SchoolPlannerField $plannerField, PlannerFields $fields): JsonResponse
    {
        abort_unless($plannerField->isCustom(), 404);

        $plannerField->update($request->attributesToSave());

        return response()->json(['data' => $fields->settings()]);
    }

    public function destroyCustom(SchoolPlannerField $plannerField, PlannerFields $fields): JsonResponse|Response
    {
        $this->authorize('manage', CurricularOffering::class);
        abort_unless($plannerField->isCustom(), 404);

        $plannerField->delete();

        return response()->json(['data' => $fields->settings()]);
    }
}
