<?php

namespace App\Http\Resources;

use App\Models\Accommodation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a teacher gets of an accommodation in the student profile (ClickUp
 * 86e3dpbwy follow-up, living document screen 04 · Ajustes): what to apply
 * (`label`), since when (`starts_on`) and where — the subjects it is
 * restricted to (empty = every subject) and the assessment types (null =
 * every type). Enough to know whether it applies in their class.
 *
 * Deliberately an allow-list (CLAUDE.md security rule 11): never the
 * description, focus area, origin (report/barrier links), who created or
 * validated it, its approval state or a review date. Those stay in
 * {@see AccommodationResource}, which only psychopedagogy/direction receive.
 *
 * @mixin Accommodation
 */
class AccommodationSupportChipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('subjects');

        return [
            'id' => $this->id,
            'label' => $this->type,
            'starts_on' => $this->starts_on?->toDateString(),
            'subjects' => AccommodationSubjectResource::collection($this->subjects),
            'assessment_types' => $this->assessment_types ?: null,
        ];
    }
}
