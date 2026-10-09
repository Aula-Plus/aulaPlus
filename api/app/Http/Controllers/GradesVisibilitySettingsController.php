<?php

namespace App\Http\Controllers;

use App\Enums\GradesVisibility;
use App\Http\Requests\UpdateGradesVisibilityRequest;
use App\Models\School;
use App\Services\StudentGradeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/PUT /api/v1/grades-visibility — the school-wide setting for how much of
 * other subjects' grades a teacher sees. Any staff member reads it (the UI
 * explains the rule); only direction writes (UpdateGradesVisibilityRequest).
 */
class GradesVisibilitySettingsController extends Controller
{
    public function show(Request $request, StudentGradeAccess $access): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->school, $access)]);
    }

    public function update(UpdateGradesVisibilityRequest $request, StudentGradeAccess $access): JsonResponse
    {
        $school = School::query()->findOrFail($request->user()->school_id);
        $mode = GradesVisibility::from($request->validated('mode'));

        $school->update([
            'grades_visibility' => $mode,
            'grades_cutoff_months' => $mode === GradesVisibility::AllPeriodic
                ? $request->validated('cutoff_months') : null,
            // Cutoffs count from the given date, or from today when first set.
            'grades_cutoff_anchor' => $mode === GradesVisibility::AllPeriodic
                ? ($request->validated('cutoff_anchor') ?? $school->grades_cutoff_anchor ?? now()->toDateString())
                : null,
        ]);

        return response()->json(['data' => $this->payload($school->refresh(), $access)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(School $school, StudentGradeAccess $access): array
    {
        return [
            'mode' => $school->grades_visibility->value,
            'cutoff_months' => $school->grades_cutoff_months,
            'cutoff_anchor' => $school->grades_cutoff_anchor?->toDateString(),
            'last_cutoff_at' => $access->lastCutoff($school)?->toDateString(),
        ];
    }
}
