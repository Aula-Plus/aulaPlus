<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentPerformanceTimelineRequest;
use App\Http\Resources\StudentAssessmentResultResource;
use App\Models\Student;
use App\Services\PerformanceTimelineBuilder;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/students/{student}/performance-timeline?from=&to=
 * (docs/prompts/20-linea-tiempo-alumno.md §1).
 *
 * Read-only aggregator that feeds the Perfil de alumno chart (Session 14,
 * frontend): the `results` performance line plus a single `marks` array
 * combining the six sources built in Sessions 3/6/8/9 and the pre-existing
 * Barrier/CalendarEvent models. Authorization (StudentPolicy::view, same as
 * the tracking view) is enforced by the FormRequest; the finer clinical /
 * comment-visibility gating of individual mark types lives in
 * PerformanceTimelineBuilder.
 */
class StudentPerformanceTimelineController extends Controller
{
    public function show(
        StudentPerformanceTimelineRequest $request,
        Student $student,
        PerformanceTimelineBuilder $builder,
    ): JsonResponse {
        $timeline = $builder->build(
            $student,
            $request->user(),
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('subject_id') !== null ? (int) $request->validated('subject_id') : null,
        );

        return response()->json([
            // Plain arrays (not the default `data`-wrapped collection): the
            // spec's response is { "results": [...], "marks": [...] }.
            'results' => StudentAssessmentResultResource::collection($timeline['results'])->resolve($request),
            'marks' => $timeline['marks'],
        ]);
    }
}
