<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * CRUD for school subjects ("materias"). Read is open to any authenticated
 * staff member (SchoolScope constrains rows to the tenant); writes are
 * director-only, enforced by StoreSubjectRequest/UpdateSubjectRequest via
 * SubjectPolicy.
 */
class SubjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subject::class);

        return SubjectResource::collection(
            Subject::query()->orderBy('name')->get()
        );
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = Subject::create($request->validated());

        return (new SubjectResource($subject))->response()->setStatusCode(201);
    }

    public function show(Subject $subject): SubjectResource
    {
        $this->authorize('view', $subject);

        return new SubjectResource($subject);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $subject->update($request->validated());

        return new SubjectResource($subject);
    }

    public function destroy(Subject $subject): Response
    {
        $this->authorize('delete', $subject);

        $subject->delete();

        return response()->noContent();
    }
}
