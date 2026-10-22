<?php

namespace App\Http\Resources;

use App\Models\AccommodationReviewEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One step of a direction proposal's review (ClickUp 86e3qkwkk). Only ever
 * returned to school-wide roles (AccommodationPolicy::viewReviewHistory); the
 * note may hold sensitive content about a minor.
 *
 * @mixin AccommodationReviewEvent
 */
class AccommodationReviewEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'note' => $this->note,
            // `edited` only: the API names of the fields changed (no content).
            'changed_fields' => $this->changed_fields,
            'user' => $this->user ? new StaffMemberResource($this->user) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
