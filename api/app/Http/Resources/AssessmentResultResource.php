<?php

namespace App\Http\Resources;

use App\Models\AssessmentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's result on an assessment (docs/prompts/13-evaluaciones-
 * resultados.md §2). Used by the per-assessment results endpoints.
 *
 * @mixin AssessmentResult
 */
class AssessmentResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'student_id' => $this->student_id,
            'score' => $this->score,
            'feedback' => $this->feedback,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
