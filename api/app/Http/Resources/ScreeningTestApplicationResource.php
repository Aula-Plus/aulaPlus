<?php

namespace App\Http\Resources;

use App\Models\ScreeningTestApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScreeningTestApplication
 */
class ScreeningTestApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'screening_test_type_id' => $this->screening_test_type_id,
            'group_id' => $this->group_id,
            'applied_by_id' => $this->applied_by_id,
            'application_date' => $this->application_date?->toDateString(),
            'results_count' => $this->whenCounted('results'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
