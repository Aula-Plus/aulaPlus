<?php

namespace App\Http\Controllers;

use App\Http\Resources\ScreeningTestDesignResource;
use App\Models\ScreeningTestDesign;
use Illuminate\Validation\ValidationException;

/**
 * Approve/reject workflow for a screening-test design (docs/prompts/
 * 11-pruebas-de-sondeo.md §1) — deliberately the same shape as
 * AccommodationApprovalController: director-only authorization in the Policy,
 * a business-state precondition (still pending) checked here as 422, and the
 * `approved` change written via update() so Concerns\Auditable records the
 * audit_logs entry automatically.
 */
class ScreeningTestDesignApprovalController extends Controller
{
    public function approve(ScreeningTestDesign $design): ScreeningTestDesignResource
    {
        $this->authorize('approve', $design);
        $this->ensurePending($design);

        $design->update([
            'approved' => true,
            'approved_by_id' => auth()->id(),
        ]);

        return new ScreeningTestDesignResource($design);
    }

    public function reject(ScreeningTestDesign $design): ScreeningTestDesignResource
    {
        $this->authorize('reject', $design);
        $this->ensurePending($design);

        $design->update([
            'approved' => false,
            'approved_by_id' => auth()->id(),
        ]);

        return new ScreeningTestDesignResource($design);
    }

    /**
     * Only a design that has not been decided yet (`approved === null`) can be
     * approved or rejected. Not an authorization check (see
     * ScreeningTestDesignPolicy::approve) — a business-state precondition that
     * responds 422, not 403.
     */
    protected function ensurePending(ScreeningTestDesign $design): void
    {
        if ($design->approved !== null) {
            throw ValidationException::withMessages([
                'design' => 'This design has already been approved or rejected.',
            ]);
        }
    }
}
