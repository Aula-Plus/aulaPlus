<?php

namespace App\Http\Controllers;

use App\Enums\AccommodationReviewStatus;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Resources\AccommodationResource;
use App\Models\Accommodation;
use App\Models\Student;
use App\Services\Accommodations\AccommodationProposalWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
 *
 * Direction proposals (ClickUp 86e3qkwkk): what direction creates is a
 * pending proposal (always active, never effective until psychopedagogy
 * confirms it) and opens psychopedagogy's review task; editing an open
 * proposal is recorded in its history and never publishes it. A closed
 * proposal (withdrawn/rejected) is kept for the record and can't be edited.
 */
class AccommodationController extends Controller
{
    public function __construct(protected AccommodationProposalWorkflow $workflow) {}

    public function store(StoreAccommodationRequest $request, Student $student): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $proposes = AccommodationProposalWorkflow::createsProposals($user);

        $accommodation = DB::transaction(function () use ($validated, $student, $user, $proposes): Accommodation {
            $accommodation = Accommodation::create([
                ...$this->attributes($validated),
                'student_id' => $student->id,
                ...($proposes ? [
                    'active' => true,
                    'review_status' => AccommodationReviewStatus::Pending,
                    'proposed_by_id' => $user->id,
                ] : []),
            ]);
            $accommodation->subjects()->sync($validated['subject_ids'] ?? []);

            if ($proposes) {
                $this->workflow->submitted(
                    $accommodation,
                    $user,
                    $validated['review_due_date'],
                    $validated['review_recipients'] === 'people' ? $validated['reviewer_ids'] : null,
                );
            }

            return $accommodation;
        });

        return (new AccommodationResource($accommodation->load('subjects')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAccommodationRequest $request, Accommodation $accommodation): AccommodationResource
    {
        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($validated, $accommodation, $user): void {
            // Re-read under a row lock: the FormRequest authorized the copy
            // route-model binding loaded, and a concurrent step (psychopedagogy
            // confirming, direction withdrawing) may have changed it since.
            // Direction must never land an edit on a confirmed accommodation.
            $this->workflow->lockFresh($accommodation);
            Gate::forUser($user)->authorize('update', $accommodation);

            if ($accommodation->review_status?->isClosedUnconfirmed()) {
                throw ValidationException::withMessages([
                    'accommodation' => 'A withdrawn or rejected proposal can no longer be edited.',
                ]);
            }

            $accommodation->update($this->attributes($validated));
            $changedFields = array_keys(Arr::except($accommodation->getChanges(), ['updated_at', 'deactivated_on']));

            if (array_key_exists('subject_ids', $validated)) {
                $synced = $accommodation->subjects()->sync($validated['subject_ids']);

                if ($synced['attached'] !== [] || $synced['detached'] !== []) {
                    $changedFields[] = 'subject_ids';
                }
            }

            // The proposal's history says who changed what (field names only,
            // never content); an edit that changed nothing is not a step.
            if ($accommodation->isOpenProposal() && $changedFields !== []) {
                $this->workflow->edited($accommodation, $user, array_values($changedFields));
            }
        });

        return new AccommodationResource($accommodation->load('subjects'));
    }

    /**
     * The validated payload minus the pivot-only `subject_ids` and the review
     * routing of a proposal (it lives on the review task), with an empty
     * `assessment_types` list normalized to null (= every type).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributes(array $validated): array
    {
        $attributes = Arr::except($validated, ['subject_ids', 'review_due_date', 'review_recipients', 'reviewer_ids']);

        if (array_key_exists('assessment_types', $attributes) && empty($attributes['assessment_types'])) {
            $attributes['assessment_types'] = null;
        }

        return $attributes;
    }
}
