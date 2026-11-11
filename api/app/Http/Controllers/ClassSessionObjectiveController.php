<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClassSessions\UpdateClassObjectiveCoverageRequest;
use App\Models\ClassSession;
use App\Models\UnitObjective;
use Illuminate\Http\JsonResponse;

/**
 * What a closed class recorded for one of its objectives (screen 12,
 * «Lo terminé»). ClassSession has no other API yet: this only corrects an
 * existing record, it does not close classes.
 */
class ClassSessionObjectiveController extends Controller
{
    public function update(
        UpdateClassObjectiveCoverageRequest $request,
        ClassSession $classSession,
        UnitObjective $objective,
    ): JsonResponse {
        $classSession->unitObjectives()->updateExistingPivot($objective->getKey(), [
            'coverage' => $request->validated('coverage'),
        ]);

        return response()->json([
            'data' => [
                'class_session_id' => $classSession->id,
                'unit_objective_id' => $objective->id,
                'coverage' => $request->validated('coverage'),
            ],
        ]);
    }
}
