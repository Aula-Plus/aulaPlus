<?php

namespace App\Http\Resources;

use App\Models\ScheduledFollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledFollowUp
 */
class ScheduledFollowUpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'description' => $this->description,
            'due_date' => $this->due_date?->toDateString(),
            // Computed server-side (app timezone) so no client re-derives the
            // date arithmetic — see docs/prompts/17-seguimiento-programado.md §3.
            'is_overdue' => $this->isOverdue(),
            'created_by_id' => $this->created_by_id,
            'resolved' => $this->resolved,
            'resolved_by_id' => $this->resolved_by_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
