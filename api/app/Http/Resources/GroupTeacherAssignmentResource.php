<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One teacher-subject assignment row of a group. The underlying record is a
 * User hydrated with the `group_teacher` pivot (subject_id) plus the loaded
 * subject relation for its display name.
 */
class GroupTeacherAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'teacher_id' => $this->id,
            'teacher_name' => $this->name,
            'subject_id' => $this->pivot->subject_id,
            'subject_name' => $this->subjectName,
        ];
    }
}
