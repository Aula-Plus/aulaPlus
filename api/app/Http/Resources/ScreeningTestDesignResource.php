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
            'cutoff_low' => $this->cutoff_low,
            'cutoff_high' => $this->cutoff_high,
            'meaning_red' => $this->meaning_red,
            'meaning_yellow' => $this->meaning_yellow,
            'meaning_green' => $this->meaning_green,
            'approved' => $this->approved,
            'approved_by_id' => $this->approved_by_id,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
