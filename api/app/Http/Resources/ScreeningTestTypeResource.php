<?php

namespace App\Http\Resources;

use App\Models\ScreeningTestType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScreeningTestType
 */
class ScreeningTestTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentDesign = $this->resource->currentApprovedDesign();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'active' => $this->active,
            'created_by_id' => $this->created_by_id,
            'current_design' => $currentDesign
                ? new ScreeningTestDesignResource($currentDesign)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
