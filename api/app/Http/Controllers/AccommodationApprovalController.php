<?php

namespace App\Http\Controllers;

use App\Contracts\StatusChangeNotifier;
use App\Http\Resources\AccommodationResource;
use App\Models\Accommodation;
use Illuminate\Validation\ValidationException;

/**
 * Approve/reject workflow for accommodations that require external approval
 * (docs/prompts/03-flujos-aprobacion-trazabilidad.md §3). The `approved`
 * change itself is written via Accommodation::update(), so
 * Concerns\Auditable records the `audit_logs` entry automatically — no
 * manual logging call needed here.
 */
class AccommodationApprovalController extends Controller
{
    public function approve(Accommodation $accommodation, StatusChangeNotifier $notifier): AccommodationResource
    {
        $this->authorize('approve', $accommodation);
        $this->ensurePendingExternalApproval($accommodation);

        $accommodation->update(['approved' => true]);

        $notifier->notify('accommodation.approved', [
            'accommodation_id' => $accommodation->id,
            'school_id' => $accommodation->school_id,
            'approved_by_id' => auth()->id(),
        ]);

        return new AccommodationResource($accommodation);
    }

    public function reject(Accommodation $accommodation, StatusChangeNotifier $notifier): AccommodationResource
    {
        $this->authorize('reject', $accommodation);
        $this->ensurePendingExternalApproval($accommodation);

        $accommodation->update(['approved' => false]);

        $notifier->notify('accommodation.rejected', [
            'accommodation_id' => $accommodation->id,
            'school_id' => $accommodation->school_id,
            'rejected_by_id' => auth()->id(),
        ]);

        return new AccommodationResource($accommodation);
    }

    /**
     * Business-state precondition (not an authorization check — see
     * AccommodationPolicy::approve()): only an accommodation that requires
     * external approval and hasn't been decided yet can be approved/rejected.
     */
    protected function ensurePendingExternalApproval(Accommodation $accommodation): void
    {
        if (! $accommodation->requires_external_approval || $accommodation->approved !== null) {
            throw ValidationException::withMessages([
                'accommodation' => 'This accommodation is not pending external approval.',
            ]);
        }
    }
}
