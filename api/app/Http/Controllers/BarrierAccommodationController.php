<?php

namespace App\Http\Controllers;

use App\Contracts\StatusChangeNotifier;
use App\Enums\AuditAction;
use App\Enums\StudentFileSection;
use App\Http\Requests\AttachBarrierAccommodationRequest;
use App\Http\Resources\BarrierAccommodationResource;
use App\Models\Accommodation;
use App\Models\Barrier;
use App\Support\StudentFileHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Barrier↔Accommodation linking and validation
 * (docs/prompts/03-flujos-aprobacion-trazabilidad.md §4): a teacher/
 * psychopedagogue proposes that an accommodation addresses a barrier, and a
 * director/psychopedagogue later validates it — never the same person for
 * both (four-eyes rule).
 *
 * The listing goes through the single accommodation visibility rule
 * (Accommodation::scopeVisibleTo): a teacher of the student only gets the
 * linked accommodations that are general or scoped to a subject they teach,
 * and BarrierAccommodationResource drops their clinical detail for anyone
 * without view-clinical-profile (CLAUDE.md security rule 11).
 */
class BarrierAccommodationController extends Controller
{
    public function index(Barrier $barrier): AnonymousResourceCollection
    {
        $this->authorize('view', $barrier);

        return BarrierAccommodationResource::collection(
            $barrier->accommodations()->visibleTo(auth()->user())->with('student')->get()
        );
    }

    public function store(AttachBarrierAccommodationRequest $request, Barrier $barrier): JsonResponse
    {
        $accommodation = Accommodation::findOrFail($request->validated('accommodation_id'));
        $alreadyLinked = $barrier->accommodations()->whereKey($accommodation->id)->exists();

        $barrier->accommodations()->syncWithoutDetaching([
            $accommodation->id => [
                'proposed_by_id' => auth()->id(),
                'validated' => false,
                'validated_by_id' => null,
            ],
        ]);

        if (! $alreadyLinked) {
            $this->recordLinkChange($barrier, $accommodation, AuditAction::Created, [
                ['accommodation', null, $accommodation->type],
                ['validated', null, false],
            ]);
        }

        return (new BarrierAccommodationResource($barrier->accommodations()->findOrFail($accommodation->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function validateLink(Barrier $barrier, Accommodation $accommodation, StatusChangeNotifier $notifier): BarrierAccommodationResource
    {
        $this->authorize('validate', $barrier);

        $linked = $barrier->accommodations()->where('accommodations.id', $accommodation->id)->firstOrFail();

        if ((int) $linked->pivot->proposed_by_id === (int) auth()->id()) {
            throw ValidationException::withMessages([
                'accommodation' => 'The user who proposed this link cannot also validate it (four-eyes rule).',
            ]);
        }

        $barrier->accommodations()->updateExistingPivot($accommodation->id, [
            'validated' => true,
            'validated_by_id' => auth()->id(),
        ]);

        $this->recordLinkChange($barrier, $accommodation, AuditAction::Updated, [
            ['validated', (bool) $linked->pivot->validated, true],
        ]);

        $notifier->notify('barrier_accommodation.validated', [
            'barrier_id' => $barrier->id,
            'accommodation_id' => $accommodation->id,
            'validated_by_id' => auth()->id(),
        ]);

        return new BarrierAccommodationResource($barrier->accommodations()->findOrFail($accommodation->id));
    }

    /**
     * The link is a pivot (no model event), so the student-file change
     * history is written here, under the barrier (section "barriers").
     *
     * @param  list<array{0: string, 1: mixed, 2: mixed}>  $changes
     */
    private function recordLinkChange(Barrier $barrier, Accommodation $accommodation, AuditAction $action, array $changes): void
    {
        StudentFileHistory::recordManual(
            $barrier,
            $barrier->student_id,
            StudentFileSection::Barriers,
            $action,
            'barrier_accommodation',
            $accommodation->type,
            $changes,
        );
    }
}
