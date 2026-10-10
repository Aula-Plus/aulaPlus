<?php

namespace App\Http\Resources;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subject an accommodation is restricted to: id, name, and whether it was
 * soft-deleted since (the accommodation stays scoped to it — it never turns
 * general — and the edit form shows it as «(eliminada)» so it can be removed).
 *
 * @mixin Subject
 */
class AccommodationSubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'deleted' => $this->deleted_at !== null,
        ];
    }
}
