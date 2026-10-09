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
            // Only loaded by the caller's own pending list (`mine`), whose rows
            // are already filtered to students the caller may see.
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'full_name' => $this->student->full_name,
            ]),
            'description' => $this->description,
            'due_date' => $this->due_date?->toDateString(),
            // Computed server-side (app timezone) so no client re-derives the
            // date arithmetic — see docs/prompts/17-seguimiento-programado.md §3.
            'is_overdue' => $this->isOverdue(),
            'created_by_id' => $this->created_by_id,
            'assigned_to_id' => $this->assigned_to_id,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? new StaffMemberResource($this->assignedTo) : null),
            'shared_with' => StaffMemberResource::collection($this->whenLoaded('sharedWith')),
            'resolved' => $this->resolved,
            'resolved_by_id' => $this->resolved_by_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
