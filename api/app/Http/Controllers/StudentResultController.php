<?php

namespace App\Http\Controllers;

use App\Http\Resources\StudentAssessmentResultResource;
use App\Models\AssessmentResult;
use App\Models\Student;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/students/{student}/results — a student's performance timeline
 * (docs/prompts/13-evaluaciones-resultados.md §2). Read-only aggregator that
 * the Perfil de alumno chart (next session) consumes, ordered by
 * `administered_at` (when the assessment was taken), NOT `created_at`.
 *
 * Same authorization as the tracking view (Session 4): StudentPolicy::view.
 */
class StudentResultController extends Controller
{
    public function index(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        // Order by the parent assessment's administered_at via a join, then
        // re-select the result columns so the models hydrate cleanly.
        $results = AssessmentResult::query()
            ->where('assessment_results.student_id', $student->id)
            ->join('assessments', 'assessments.id', '=', 'assessment_results.assessment_id')
            ->orderBy('assessments.administered_at')
            ->orderBy('assessment_results.id')
            ->select('assessment_results.*')
            ->with('assessment')
            ->get();

        return StudentAssessmentResultResource::collection($results);
    }
}
