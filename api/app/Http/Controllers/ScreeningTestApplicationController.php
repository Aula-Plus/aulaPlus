<?php

namespace App\Http\Controllers;

use App\Actions\Screening\CreateScreeningTestApplication;
use App\Http\Requests\StoreScreeningTestApplicationRequest;
use App\Http\Resources\ScreeningTestApplicationResource;
use App\Http\Resources\ScreeningTestResultResource;
use App\Models\Group;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applying a screening test to a group and reading it back (docs/prompts/
 * 11-pruebas-de-sondeo.md §§2-3). The roster (code -> student) is the ONLY
 * endpoint that de-anonymises results; the by-code results view never carries
 * a name (see ScreeningTestResultResource).
 */
class ScreeningTestApplicationController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [ScreeningTestApplication::class, $group]);

        $applications = ScreeningTestApplication::query()
            ->where('group_id', $group->id)
            ->withCount('results')
            ->orderByDesc('id')
            ->get();

        return ScreeningTestApplicationResource::collection($applications);
    }

    public function store(
        StoreScreeningTestApplicationRequest $request,
        Group $group,
        CreateScreeningTestApplication $createApplication,
    ): JsonResponse {
        // Tenant-scoped: a type from another school never resolves here.
        $type = ScreeningTestType::query()->findOrFail($request->validated('screening_test_type_id'));

        // A screening test cannot be applied without an approved design in
        // force (spec §2) — business-state precondition, 422 not 403.
        abort_if(
            $type->currentApprovedDesign() === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This screening test type has no approved design in force.',
        );

        $application = $createApplication(
            $group,
            $type,
            $request->user(),
            $request->validated('application_date'),
        );

        return (new ScreeningTestApplicationResource($application->loadCount('results')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * The printable code -> student roster. Psychopedagogy only (spec §2); this
     * is the single place code and full_name are ever crossed.
     */
    public function roster(ScreeningTestApplication $application): JsonResponse
    {
        $this->authorize('viewRoster', $application);

        $roster = $application->results()
            ->with('student:id,full_name')
            ->orderBy('code')
            ->get()
            ->map(fn ($result): array => [
                'code' => $result->code,
                'student_id' => $result->student_id,
                'full_name' => $result->student?->full_name,
            ])
            ->values();

        return response()->json(['data' => $roster]);
    }

    /**
     * Results by code, no names (spec §3). Psychopedagogy only.
     */
    public function results(ScreeningTestApplication $application): AnonymousResourceCollection
    {
        $this->authorize('viewResults', $application);

        $results = $application->results()->orderBy('code')->get();

        return ScreeningTestResultResource::collection($results);
    }
}
