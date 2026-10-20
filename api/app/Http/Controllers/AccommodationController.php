<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Resources\AccommodationResource;
use App\Models\Accommodation;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
 *
 * `subject_ids` is not a column: it is synced into accommodation_subject in
 * the same transaction (an empty list = general). An empty `assessment_types`
 * list is stored as null — "every type" has one representation only.
 */
class AccommodationController extends Controller
{
    public function store(StoreAccommodationRequest $request, Student $student): JsonResponse
    {
        $validated = $request->validated();

        $accommodation = DB::transaction(function () use ($validated, $student): Accommodation {
            $accommodation = Accommodation::create([
                ...$this->attributes($validated),
                'student_id' => $student->id,
            ]);
            $accommodation->subjects()->sync($validated['subject_ids'] ?? []);

            return $accommodation;
        });

        return (new AccommodationResource($accommodation->load('subjects')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAccommodationRequest $request, Accommodation $accommodation): AccommodationResource
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $accommodation): void {
            $accommodation->update($this->attributes($validated));

            if (array_key_exists('subject_ids', $validated)) {
                $accommodation->subjects()->sync($validated['subject_ids']);
            }
        });

        return new AccommodationResource($accommodation->load('subjects'));
    }

    /**
     * The validated payload minus the pivot-only `subject_ids`, with an empty
     * `assessment_types` list normalized to null (= every type).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributes(array $validated): array
    {
        $attributes = Arr::except($validated, ['subject_ids']);

        if (array_key_exists('assessment_types', $attributes) && empty($attributes['assessment_types'])) {
            $attributes['assessment_types'] = null;
        }

        return $attributes;
    }
}
