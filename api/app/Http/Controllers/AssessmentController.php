<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssessmentRequest;
use App\Http\Requests\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * CRUD for assessments (docs/prompts/13-evaluaciones-resultados.md §1). Fills
 * the gap left by Session 1: the domain model existed but had no endpoints.
 *
 * Write authorization is layered: the FormRequests enforce the owning-teacher /
 * teaches-group rules (AssessmentPolicy + the group-specific teachesGroup check
 * the spec asks to keep in the request layer). The read endpoints authorize
 * against the parent group / assessment here.
 */
class AssessmentController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $this->authorize('view', $group);

        return AssessmentResource::collection(
            $group->assessments()->latest('administered_at')->get()
        );
    }

    public function store(StoreAssessmentRequest $request, Group $group): JsonResponse
    {
        $assessment = Assessment::create([
            ...$request->validated(),
            'group_id' => $group->id,
            'teacher_id' => $request->user()->id,
        ]);

        return (new AssessmentResource($assessment))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment): AssessmentResource
    {
        $assessment->update($request->validated());

        return new AssessmentResource($assessment);
    }

    public function destroy(Assessment $assessment): Response
    {
        $this->authorize('delete', $assessment);

        $assessment->delete();

        return response()->noContent();
    }
}
