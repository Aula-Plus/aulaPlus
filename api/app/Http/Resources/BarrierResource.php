<?php

namespace App\Http\Resources;

use App\Models\Barrier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standalone Barrier representation (as opposed to BarrierAccommodationResource,
 * which represents an Accommodation from a Barrier's perspective). Used by the
 * student tracking view (docs/prompts/04-seguimiento-institucional.md §2).
 *
 * @mixin Barrier
 */
class BarrierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'coping_strategy' => $this->coping_strategy,
            'active' => $this->active,
        ];
    }
}
