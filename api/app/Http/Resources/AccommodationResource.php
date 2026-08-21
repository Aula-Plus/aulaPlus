<?php

namespace App\Http\Resources;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Accommodation
 */
class AccommodationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'type' => $this->type,
            'active' => $this->active,
            'description' => $this->description,
            'focus_area' => $this->focus_area,
            'requires_external_approval' => $this->requires_external_approval,
            'approved' => $this->approved,
            'is_effective' => $this->resource->isEffective(),
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
