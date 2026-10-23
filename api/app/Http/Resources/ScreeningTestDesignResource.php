<?php

namespace App\Http\Resources;

use App\Models\ScreeningTestDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScreeningTestDesign
 */
class ScreeningTestDesignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'screening_test_type_id' => $this->screening_test_type_id,
            'bands' => $this->resource->bandList(),
            'reason' => $this->reason,
            'approved' => $this->approved,
            'created_by_id' => $this->created_by_id,
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'reverted_at' => $this->reverted_at?->toIso8601String(),
            'reverted_by_id' => $this->reverted_by_id,
            'reverted_by_name' => $this->whenLoaded('revertedBy', fn () => $this->revertedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
