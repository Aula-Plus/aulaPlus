<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStudentTeamSummaryRequest;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

/**
 * PUT /api/v1/students/{student}/team-summary — psychopedagogy writes and
 * confirms the team-facing summary. Saving confirms it: nothing is shown to the
 * team until this has been saved at least once. Readers get it through the
 * student tracking payload.
 */
class StudentTeamSummaryController extends Controller
{
    public function update(UpdateStudentTeamSummaryRequest $request, Student $student): JsonResponse
    {
        $data = $request->validated();

        $student->forceFill([
            'team_summary_strengths' => $data['strengths'],
            'team_summary_difficulties' => $data['difficulties'],
            'team_summary_adjustments' => $data['adjustments'],
            'team_summary_confirmed_at' => now(),
            'team_summary_confirmed_by_id' => $request->user()->id,
        ])->save();

        return response()->json(['data' => $student->teamSummary()]);
    }
}
