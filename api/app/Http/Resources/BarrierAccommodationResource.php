<?php

namespace App\Http\Resources;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Accommodation as linked to a Barrier, including the barrier_accommodation
 * pivot's proposal/validation state.
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
        return [
            'id' => $this->id,
            'type' => $this->type,
            'description' => $this->description,
            'focus_area' => $this->focus_area,
            'proposed_by_id' => $this->pivot->proposed_by_id,
            'validated' => (bool) $this->pivot->validated,
            'validated_by_id' => $this->pivot->validated_by_id,
        ];
    }
}
