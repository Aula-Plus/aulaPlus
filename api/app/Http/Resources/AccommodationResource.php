<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full accommodation record — returned to psychopedagogy and direction
 * (the student profile gates it behind view-clinical-profile), and to a
 * teacher only for their own proposal, without its review fields.
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
        // A teacher only ever gets this for their own proposal (ClickUp
        // 1dfd3mc55zu): never «Origen», the report it came from, nor who
        // reviewed it (P04: «No ves el origen, el informe ni quién lo
        // confirmó»).
        $schoolWide = (bool) $request->user()?->hasAnyRole(Role::schoolWideValues());

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
            // «Origen» (ClickUp 86e3qkwkk): this resource only ever reaches
            // psychopedagogy and direction; the teacher's chip never carries it.
            'origin' => $schoolWide ? $this->origin : null,
            // Report the accommodation was approved from, when any: lets the
            // reader open the file (ReportDocumentPolicy still decides).
            'origin_report_id' => $schoolWide ? $this->origin_report_id : null,
            'is_effective' => $this->resource->isEffective(),
            // Direction proposal circuit (ClickUp 86e3qkwkk): null when
            // psychopedagogy created it directly. `review_note` is the comment
            // or reason of the step that put it in its current state (returned,
            // withdrawn, rejected); the full history has its own endpoint.
            'review_status' => $this->review_status?->value,
            'proposed_by_id' => $this->proposed_by_id,
            // «director» or «teacher»: who the proposal comes from.
            'proposed_as' => $this->proposed_as,
            // Names only, for «Propuesta de …» and «Confirmó …».
            'proposed_by_name' => $this->proposed_by_id !== null ? $this->proposedBy?->name : null,
            'reviewed_by_id' => $schoolWide ? $this->reviewed_by_id : null,
            'reviewed_by_name' => $schoolWide && $this->reviewed_by_id !== null ? $this->reviewedBy?->name : null,
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

        // Eager-loaded on lists (no query per row); queried for a single one.
        return $this->resource->relationLoaded('latestReviewEvent')
            ? $this->resource->latestReviewEvent?->note
            : $this->resource->reviewEvents()->reorder('id', 'desc')->value('note');
    }
}
