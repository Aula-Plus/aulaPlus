<?php

namespace App\Http\Resources;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact Assessment representation for the student tracking view
 * (docs/prompts/04-seguimiento-institucional.md §2) — not a full Assessment
 * CRUD resource, just enough to show recent activity.
 *
 * @mixin Assessment
 */
class AssessmentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'type' => $this->type->value,
            'variant_number' => $this->variant_number,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
