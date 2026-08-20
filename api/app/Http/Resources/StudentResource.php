<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin Student
 *
 * Field-level authorization pattern (CLAUDE.md security rule 11: sensitive
 * data about a minor needs per-field access, not just per-endpoint): a single
 * Resource serializes the whole model, but decides *inside* toArray() which
 * fields to include based on a Gate check against the current request user —
 * never two parallel endpoints/Resources for the same model. A teacher who
 * passes StudentPolicy::view (because they teach the student) still only gets
 * the basic/roster fields here; only director/psychopedagogue (see
 * StudentPolicy::viewClinicalProfile) get the clinical/learning profile
 * fields. This same pattern applies to other sensitive Resources
 * (BarrierResource, and partially AccommodationResource).
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewClinicalProfile = Gate::forUser($request->user())
            ->allows('view-clinical-profile', $this->resource);

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'photo_url' => $this->photo_url,
            'birth_date' => $this->birth_date?->toDateString(),
            'enrollment_year' => $this->enrollment_year,
            'has_therapeutic_companion' => $this->has_therapeutic_companion,
            'groups' => $this->whenLoaded('groups', fn () => $this->groups->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'school_year' => $group->pivot->school_year,
            ])->all()),
            'learning_profile' => $this->when($canViewClinicalProfile, $this->learning_profile),
            'tracking_notes' => $this->when($canViewClinicalProfile, $this->tracking_notes),
            'individual_profile' => $this->when($canViewClinicalProfile, $this->individual_profile),
            'related_documents' => $this->when($canViewClinicalProfile, $this->related_documents),
        ];
    }
}
