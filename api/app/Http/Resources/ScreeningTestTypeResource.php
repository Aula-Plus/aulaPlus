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
        $ruled = $this->resource->ruledDesigns(2);
        $currentDesign = $ruled->get(0);
        $previousDesign = $ruled->get(1);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'area' => $this->area,
            'levels' => $this->levels ?? [],
            'frequency' => $this->frequency?->value,
            'frequency_years' => $this->frequency_years,
            'teacher_visible' => $this->teacher_visible,
            'active' => $this->active,
            'created_by_id' => $this->created_by_id,
            'current_design' => $currentDesign
                ? new ScreeningTestDesignResource($currentDesign)
                : null,
            'previous_design' => $previousDesign
                ? new ScreeningTestDesignResource($previousDesign)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
