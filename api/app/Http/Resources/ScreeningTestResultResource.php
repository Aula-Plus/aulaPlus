<?php

namespace App\Http\Resources;

use App\Models\ScreeningTestResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The anonymised view of a result (docs/prompts/11-pruebas-de-sondeo.md §3):
 * by code only, NEVER carrying `student_id` or the student's name. The
 * code -> student mapping is exposed solely by the roster endpoint
 * (ScreeningTestRosterController). Do not add `student_id` here.
 *
 * @mixin ScreeningTestResult
 */
class ScreeningTestResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'screening_test_application_id' => $this->screening_test_application_id,
            'code' => $this->code,
            'score' => $this->score,
            'color' => $this->color?->value,
            'loaded_by_id' => $this->loaded_by_id,
            'loaded_at' => $this->loaded_at?->toIso8601String(),
        ];
    }
}
