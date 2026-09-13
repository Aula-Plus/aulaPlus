<?php

namespace App\Http\Resources;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full Assessment representation for the CRUD endpoints
 * (docs/prompts/13-evaluaciones-resultados.md §1). The compact
 * {@see AssessmentSummaryResource} is used by the tracking view instead.
 *
 * @mixin Assessment
 */
class AssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'teacher_id' => $this->teacher_id,
            'type' => $this->type->value,
            'purpose' => $this->purpose,
            'duration_minutes' => $this->duration_minutes,
            'variant_number' => $this->variant_number,
            'administered_at' => $this->administered_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
