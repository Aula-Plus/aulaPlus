<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClassSessions\UpdateClassObjectiveCoverageRequest;
use App\Models\ClassSession;
use App\Models\UnitObjective;
use Illuminate\Http\JsonResponse;

/**
 * «Lo terminé» (screen 12): record one objective as «todo» on the class the
 * teacher picked. The link is created if the class did not work the
 * objective yet; every other class's record is kept. Closing a class as such
 * belongs to the class screens (14–17).
 */
class ClassSessionObjectiveController extends Controller
{
    public function update(
        UpdateClassObjectiveCoverageRequest $request,
        ClassSession $classSession,
        UnitObjective $objective,
    ): JsonResponse {
        $previous = $classSession->unitObjectives()->whereKey($objective->getKey())->first()?->pivot?->coverage;

        $classSession->unitObjectives()->syncWithoutDetaching([
            $objective->getKey() => ['coverage' => $request->validated('coverage')],
        ]);

        return response()->json([
            'data' => [
                'class_session_id' => $classSession->id,
                'unit_objective_id' => $objective->id,
                'previous_coverage' => $previous,
                'coverage' => $request->validated('coverage'),
            ],
        ]);
    }
}
