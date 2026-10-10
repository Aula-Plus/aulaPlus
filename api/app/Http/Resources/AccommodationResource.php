<?php

namespace App\Http\Resources;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full accommodation record — only ever returned to psychopedagogy and
 * direction (the student profile gates it behind view-clinical-profile). A
 * teacher gets {@see AccommodationSupportChipResource} instead.
 *
 * @mixin Accommodation
 */
class AccommodationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Always present (not whenLoaded): approve/reject responses are spliced
        // into the SPA's list as-is, so the scope must never go missing.
        $this->resource->loadMissing('subjects');

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'type' => $this->type,
            'category' => $this->category?->value,
            'starts_on' => $this->starts_on?->toDateString(),
            'subjects' => AccommodationSubjectResource::collection($this->subjects),
            'assessment_types' => $this->assessment_types ?: null,
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
