<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'photo_url' => $this->photo_url,
            'birth_date' => $this->birth_date?->toDateString(),
            'enrollment_year' => $this->enrollment_year,
            'has_therapeutic_companion' => $this->has_therapeutic_companion,
            'learning_profile' => $this->learning_profile,
            'tracking_notes' => $this->tracking_notes,
            'individual_profile' => $this->individual_profile,
            'related_documents' => $this->related_documents,
            'groups' => $this->whenLoaded('groups', fn () => $this->groups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'school_year' => $group->pivot->school_year,
            ])->all()),
        ];
    }
}
