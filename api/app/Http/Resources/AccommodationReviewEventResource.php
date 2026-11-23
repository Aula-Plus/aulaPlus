<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\AccommodationReviewEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One step of a proposal's review (ClickUp 86e3qkwkk). Returned to
 * school-wide roles and to the teacher who proposed it
 * (AccommodationPolicy::viewReviewHistory); the note may hold sensitive
 * content about a minor.
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
            // A teacher reads the history of their own proposal (ClickUp
            // 1dfd3mc55zu) but never who reviewed it (P04: «No ves […] quién
            // lo confirmó»): only their own steps carry a name.
            'user' => $this->user && $this->showsActor($request) ? new StaffMemberResource($this->user) : null,
            'actor_hidden' => $this->user !== null && ! $this->showsActor($request),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected function showsActor(Request $request): bool
    {
        $viewer = $request->user();

        return $viewer !== null
            && ($viewer->hasAnyRole(Role::schoolWideValues()) || $this->user_id === $viewer->id);
    }
}
