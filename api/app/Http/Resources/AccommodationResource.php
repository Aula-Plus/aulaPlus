<?php

namespace App\Http\Resources;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full accommodation record — only ever returned to psychopedagogy and
 * direction (the student profile gates it behind view-clinical-profile).
 * `requires_external_approval`/`approved` are no longer exposed (ClickUp
 * 86e3qkwkk): the stored values are kept, not shown. A
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
            // Set by the server the day it was switched off; null while in force.
            'deactivated_on' => $this->deactivated_on?->toDateString(),
            'subjects' => AccommodationSubjectResource::collection($this->subjects),
            'assessment_types' => $this->assessment_types ?: null,
            'active' => $this->active,
            'description' => $this->description,
            'focus_area' => $this->focus_area,
            'is_effective' => $this->resource->isEffective(),
            // Direction proposal circuit (ClickUp 86e3qkwkk): null when
            // psychopedagogy created it directly. `review_note` is the comment
            // or reason of the step that put it in its current state (returned,
            // withdrawn, rejected); the full history has its own endpoint.
            'review_status' => $this->review_status?->value,
            'proposed_by_id' => $this->proposed_by_id,
            'reviewed_by_id' => $this->reviewed_by_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->reviewNote(),
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The note of the latest step, while the proposal sits in a state that
     * step put it in with a note (returned, withdrawn, rejected).
     */
    protected function reviewNote(): ?string
    {
        if (! in_array($this->review_status?->value, ['returned', 'withdrawn', 'rejected'], true)) {
            return null;
        }

        return $this->resource->reviewEvents()->reorder('id', 'desc')->value('note');
    }
}
