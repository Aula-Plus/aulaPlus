<?php

namespace App\Http\Controllers;

use App\Contracts\StatusChangeNotifier;
use App\Http\Requests\AttachBarrierAccommodationRequest;
use App\Http\Resources\BarrierAccommodationResource;
use App\Models\Accommodation;
use App\Models\Barrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Barrier↔Accommodation linking and validation
 * (docs/prompts/03-flujos-aprobacion-trazabilidad.md §4): a teacher/
 * psychopedagogue proposes that an accommodation addresses a barrier, and a
 * director/psychopedagogue later validates it — never the same person for
 * both (four-eyes rule).
 */
class BarrierAccommodationController extends Controller
{
    public function index(Barrier $barrier): AnonymousResourceCollection
    {
        $this->authorize('view', $barrier);

        return BarrierAccommodationResource::collection($barrier->accommodations()->get());
    }

    public function store(AttachBarrierAccommodationRequest $request, Barrier $barrier): JsonResponse
    {
        $accommodation = Accommodation::findOrFail($request->validated('accommodation_id'));

        $barrier->accommodations()->syncWithoutDetaching([
            $accommodation->id => [
                'proposed_by_id' => auth()->id(),
                'validated' => false,
                'validated_by_id' => null,
            ],
        ]);

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

        $notifier->notify('barrier_accommodation.validated', [
            'barrier_id' => $barrier->id,
            'accommodation_id' => $accommodation->id,
            'validated_by_id' => auth()->id(),
        ]);

        return new BarrierAccommodationResource($barrier->accommodations()->findOrFail($accommodation->id));
    }
}
