<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubjectRequest;
use App\Http\Requests\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\AnnualPlan;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // The subject_id FKs on assessments/annual_plans are restrictOnDelete,
        // but Subject soft-deletes, so that DB restriction never fires. Enforce
        // the same intent in the app: refuse to delete a subject still referenced
        // by assessments, annual plans, or teacher assignments, so nothing is
        // left pointing at a deleted subject.
        if ($this->isInUse($subject)) {
            throw ValidationException::withMessages([
                'subject' => 'This subject is in use and cannot be deleted.',
            ]);
        }

        $subject->delete();

        return response()->noContent();
    }

    /**
     * Whether anything still references this subject (assessments, annual plans,
     * or teacher-subject assignments). Tenancy is already enforced: the subject
     * and its references all live in the current school.
     */
    protected function isInUse(Subject $subject): bool
    {
        return $subject->assessments()->exists()
            || AnnualPlan::where('subject_id', $subject->id)->exists()
            || DB::table('group_teacher')->where('subject_id', $subject->id)->exists();
    }
}
