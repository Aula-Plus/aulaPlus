<?php

namespace App\Http\Resources;

use App\Models\AccommodationInstanceOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccommodationInstanceOverride
 */
class AccommodationInstanceOverrideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'assessment_id' => $this->assessment_id,
            'deactivated_by_id' => $this->deactivated_by_id,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
