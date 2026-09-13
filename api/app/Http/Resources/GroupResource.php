<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Group
 */
class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'school_year' => $this->school_year,
            'group_profile' => $this->group_profile,
            'related_documents' => $this->related_documents,
            // Aggregate count of students with active tracking, added by
            // GroupController::index for the groups listing (docs/prompts/
            // 22-grupos-listado.md). Aggregate only — never a name. Omitted
            // on endpoints that don't compute it (show/store/update) rather
            // than reported as a misleading 0.
            'active_tracking_count' => $this->when(
                $this->resource->getAttribute('active_tracking_count') !== null,
                fn () => (int) $this->active_tracking_count,
            ),
            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->name,
            ])->all()),
        ];
    }
}
