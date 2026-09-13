<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Resources\AccommodationResource;
use App\Models\Accommodation;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

/**
 * Create/edit endpoints for accommodations (docs/prompts/18-ajustes-categoria-
 * instancia.md §1). Session 1 shipped the model, migration and
 * AccommodationPolicy (create/update) but never the endpoints where `category`
 * must be enforced — this closes that gap.
 *
 * Authorization lives in the FormRequests, which reuse the existing
 * AccommodationPolicy abilities untouched (role check on create, shares-school
 * on update). Route-model binding runs through the SchoolScope, so cross-tenant
 * {student}/{accommodation} 404 before anything writes.
 */
class AccommodationController extends Controller
{
    public function store(StoreAccommodationRequest $request, Student $student): JsonResponse
    {
        $accommodation = Accommodation::create([
            ...$request->validated(),
            'student_id' => $student->id,
        ]);

        return (new AccommodationResource($accommodation))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAccommodationRequest $request, Accommodation $accommodation): AccommodationResource
    {
        $accommodation->update($request->validated());

        return new AccommodationResource($accommodation);
    }
}
