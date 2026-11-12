<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChooseResultScaleRequest;
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

        return response()->json(['data' => $this->scalePayload($request, $assessment, $scales)]);
    }

    /**
     * PUT /assessments/{assessment}/result-scale — the teacher chooses which
     * of the group's valid scales this assessment is recorded with, before
     * the first result (ClickUp 1dfd3mc4bgg, PO 12/11). Kept with the
     * assessment so the recorded results stay tied to it.
     */
    public function chooseScale(ChooseResultScaleRequest $request, Assessment $assessment, ResultScales $scales): JsonResponse
    {
        $assessment->forceFill(['official_scale_id' => (int) $request->validated('official_scale_id')])->save();

        return response()->json(['data' => $this->scalePayload($request, $assessment->refresh(), $scales)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function scalePayload(Request $request, Assessment $assessment, ResultScales $scales): array
    {
        ['scale' => $scale, 'choices' => $choices] = $scales->resolveForAssessment($assessment);

        if ($scale === null && $choices->isNotEmpty()) {
            return [
                'mode' => 'choose',
                'scale' => null,
                'numeric_grade' => null,
                'choices' => OfficialScaleResource::collection($choices)->resolve($request),
            ];
        }

        if ($scale === null) {
            return ['mode' => 'score', 'scale' => null, 'numeric_grade' => null];
        }

        $school = School::query()->findOrFail($assessment->school_id);
        $equivalences = $scales->equivalences($school);
        $schoolEquivalence = $scales->hasEquivalenceFor($scale, $equivalences);

        return [
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
        ];
    }

    /**
     * Upsert a batch of results, keyed by (assessment_id, student_id) so
     * re-submitting a student's result updates the existing row instead of
     * duplicating it (the unique constraint backs this). Wrapped in a
     * transaction so a partial batch never lands.
     */
    public function store(StoreAssessmentResultsRequest $request, Assessment $assessment, ResultScales $scales): AnonymousResourceCollection
    {
        $scale = $request->scale();
        $teacherWritesNumber = $request->teacherWritesNumber();

        $results = DB::transaction(function () use ($request, $assessment, $scale, $teacherWritesNumber): array {
            // The scale is kept with the assessment from its first result on,
            // so later changes to the group's frameworks never re-label it.
            if ($scale !== null && $assessment->official_scale_id === null) {
                $assessment->forceFill(['official_scale_id' => $scale->id])->save();
            }

            return array_map(
                fn (array $row): AssessmentResult => $assessment->results()->updateOrCreate(
                    ['student_id' => $row['student_id']],
                    $this->attributesFor($row, $scale !== null, $teacherWritesNumber),
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
     * Only the fields of the mode in use are written. The other mode's value
     * is cleared only on an explicit `replace_previous` (the FormRequest
     * rejects a silent overwrite), and the teacher's number is written only
     * when the teacher may write it and sent it: turning the switch off or
     * configuring a school equivalence never erases it (PO 12/11).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function attributesFor(array $row, bool $byScale, bool $teacherWritesNumber): array
    {
        $replace = (bool) ($row['replace_previous'] ?? false);
        $attributes = ['feedback' => $row['feedback'] ?? null];

        if ($byScale) {
            $attributes['official_scale_level_id'] = (int) $row['scale_level_id'];

            if ($teacherWritesNumber && array_key_exists('numeric_grade', $row)) {
                $attributes['numeric_grade'] = $row['numeric_grade'] === null ? null : (int) $row['numeric_grade'];
            }

            if ($replace) {
                $attributes['score'] = null;
            }

            return $attributes;
        }

        $attributes['score'] = $row['score'];

        if ($replace) {
            $attributes['official_scale_level_id'] = null;
            $attributes['numeric_grade'] = null;
        }

        return $attributes;
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
