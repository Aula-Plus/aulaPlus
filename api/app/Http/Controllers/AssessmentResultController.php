<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssessmentResultsRequest;
use App\Http\Resources\AssessmentResultResource;
use App\Http\Resources\OfficialScaleResource;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\School;
use App\Services\ResultScales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Results for a single assessment (docs/prompts/13-evaluaciones-resultados.md
 * §2). Reads are school-wide (a score is not clinical data); writes are limited
 * to the owning teacher — both enforced by AssessmentResultPolicy.
 */
class AssessmentResultController extends Controller
{
    public function index(Assessment $assessment, ResultScales $scales): AnonymousResourceCollection
    {
        $this->authorize('view', [AssessmentResult::class, $assessment]);

        return $this->resources($assessment, $assessment->results()->with('scaleLevel')->get()->all(), $scales);
    }

    /**
     * GET /assessments/{assessment}/result-scale — how this assessment's
     * results are recorded (ClickUp 1dfd3mc4bgg): a decimal score, or a level
     * of the group's official Primaria scale (levels in order + citation), and
     * whether / where from the number next to the level comes. Same access as
     * reading the results.
     */
    public function scale(Request $request, Assessment $assessment, ResultScales $scales): JsonResponse
    {
        $this->authorize('view', [AssessmentResult::class, $assessment]);

        $scale = $assessment->group ? $scales->scaleForGroup($assessment->group) : null;

        if ($scale === null) {
            return response()->json(['data' => ['mode' => 'score', 'scale' => null, 'numeric_grade' => null]]);
        }

        $school = School::query()->findOrFail($assessment->school_id);
        $equivalences = $scales->equivalences($school);
        $schoolEquivalence = $scales->hasEquivalenceFor($scale, $equivalences);
        $scale->load(['curricularFramework', 'educationLevel']);

        return response()->json(['data' => [
            'mode' => 'scale',
            'scale' => (new OfficialScaleResource($scale))->resolve($request),
            'numeric_grade' => [
                'shown' => (bool) $school->show_primary_numeric_grade,
                'source' => $school->show_primary_numeric_grade
                    ? ($schoolEquivalence ? 'school' : 'teacher')
                    : null,
                'equivalences' => $school->show_primary_numeric_grade && $schoolEquivalence
                    ? $scale->levels
                        ->filter(fn ($level) => isset($equivalences[$level->id]))
                        ->map(fn ($level) => ['level_id' => $level->id, 'numeric_grade' => $equivalences[$level->id]])
                        ->values()
                        ->all()
                    : [],
            ],
        ]]);
    }

    /**
     * Upsert a batch of results, keyed by (assessment_id, student_id) so
     * re-submitting a student's result updates the existing row instead of
     * duplicating it (the unique constraint backs this). Wrapped in a
     * transaction so a partial batch never lands.
     */
    public function store(StoreAssessmentResultsRequest $request, Assessment $assessment, ResultScales $scales): AnonymousResourceCollection
    {
        $byScale = $request->scale() !== null;

        $results = DB::transaction(function () use ($request, $assessment, $byScale): array {
            return array_map(
                fn (array $row): AssessmentResult => $assessment->results()->updateOrCreate(
                    ['student_id' => $row['student_id']],
                    [
                        // Exactly one of score / level is set (the Form
                        // Request decides which for this group).
                        'score' => $byScale ? null : $row['score'],
                        'official_scale_level_id' => $byScale ? (int) $row['scale_level_id'] : null,
                        'numeric_grade' => $byScale && isset($row['numeric_grade']) ? (int) $row['numeric_grade'] : null,
                        'feedback' => $row['feedback'] ?? null,
                    ],
                ),
                $request->validated('results'),
            );
        });

        foreach ($results as $result) {
            $result->load('scaleLevel');
        }

        return $this->resources($assessment, $results, $scales);
    }

    /**
     * Wrap results, resolving the number next to each level with a single
     * read of the school's switch and equivalence.
     *
     * @param  list<AssessmentResult>  $results
     */
    protected function resources(Assessment $assessment, array $results, ResultScales $scales): AnonymousResourceCollection
    {
        $school = School::query()->findOrFail($assessment->school_id);
        $equivalences = $scales->equivalences($school);

        $collection = AssessmentResultResource::collection($results);
        $collection->collection->each(function (AssessmentResultResource $resource) use ($scales, $school, $equivalences): void {
            $resource->numeric = $scales->numericGradeFor($resource->resource, $school, $equivalences);
        });

        return $collection;
    }
}
