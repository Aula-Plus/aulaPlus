<?php

namespace App\Http\Resources;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * An Accommodation as linked to a Barrier, including the barrier_accommodation
 * pivot's proposal/validation state.
 *
 * `description` and `focus_area` are clinical detail (CLAUDE.md security rule
 * 11): only included for a viewer who passes view-clinical-profile on the
 * student (psychopedagogy/direction) — a teacher gets the same allow-list as
 * AccommodationSupportChipResource (what to apply, never why). The keys are
 * omitted, not nulled, so their absence can't be mistaken for an empty value.
 *
 * @mixin Accommodation
 */
class BarrierAccommodationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewClinicalProfile = Gate::forUser($request->user())
            ->allows('view-clinical-profile', $this->resource->student);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'description' => $this->when($canViewClinicalProfile, fn () => $this->description),
            'focus_area' => $this->when($canViewClinicalProfile, fn () => $this->focus_area),
            'proposed_by_id' => $this->pivot->proposed_by_id,
            'validated' => (bool) $this->pivot->validated,
            'validated_by_id' => $this->pivot->validated_by_id,
        ];
    }
}
