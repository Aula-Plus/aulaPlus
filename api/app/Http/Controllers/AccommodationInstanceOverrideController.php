<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccommodationInstanceOverrideRequest;
use App\Http\Resources\AccommodationInstanceOverrideResource;
use App\Models\Accommodation;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-instance accommodation deactivations (docs/prompts/18-ajustes-categoria-
 * instancia.md §2): a teacher records, for one assessment, that a given
 * accommodation does not apply this time, with a mandatory reason. The
 * accommodation stays in force for every other assessment.
 */
class AccommodationInstanceOverrideController extends Controller
{
    /**
     * List the overrides recorded for one assessment. Gated through the parent
     * assessment (AssessmentPolicy::view = owning teacher or a school-wide
     * role, within the same school): the owning teacher administering the
     * assessment needs to see which accommodations are switched off, while the
     * clinical `reason` still never crosses tenants. This is at least as strict
     * as the override's own AccommodationInstanceOverridePolicy::view
     * (shares-school), the rule the spec names for reading these records.
     */
    public function index(Assessment $assessment): AnonymousResourceCollection
    {
        $this->authorize('view', $assessment);

        return AccommodationInstanceOverrideResource::collection(
            $assessment->instanceOverrides()->latest()->get()
        );
    }

    public function store(
        StoreAccommodationInstanceOverrideRequest $request,
        Accommodation $accommodation
    ): JsonResponse {
        $override = $accommodation->instanceOverrides()->create([
            'assessment_id' => $request->validated('assessment_id'),
            'deactivated_by_id' => $request->user()->id,
            'reason' => $request->validated('reason'),
        ]);

        return (new AccommodationInstanceOverrideResource($override))
            ->response()
            ->setStatusCode(201);
    }
}
