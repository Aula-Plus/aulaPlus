<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssessmentResultsRequest;
use App\Http\Resources\AssessmentResultResource;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Results for a single assessment (docs/prompts/13-evaluaciones-resultados.md
 * §2). Reads are school-wide (a score is not clinical data); writes are limited
 * to the owning teacher — both enforced by AssessmentResultPolicy.
 */
class AssessmentResultController extends Controller
{
    public function index(Assessment $assessment): AnonymousResourceCollection
    {
        $this->authorize('view', [AssessmentResult::class, $assessment]);

        return AssessmentResultResource::collection(
            $assessment->results()->get()
        );
    }

    /**
     * Upsert a batch of results, keyed by (assessment_id, student_id) so
     * re-submitting a student's result updates the existing row instead of
     * duplicating it (the unique constraint backs this). Wrapped in a
     * transaction so a partial batch never lands.
     */
    public function store(StoreAssessmentResultsRequest $request, Assessment $assessment): AnonymousResourceCollection
    {
        $results = DB::transaction(function () use ($request, $assessment): array {
            return array_map(
                fn (array $row): AssessmentResult => $assessment->results()->updateOrCreate(
                    ['student_id' => $row['student_id']],
                    ['score' => $row['score'], 'feedback' => $row['feedback'] ?? null],
                ),
                $request->validated('results'),
            );
        });

        return AssessmentResultResource::collection($results);
    }
}
