<?php

namespace App\Http\Resources;

use App\Models\AssessmentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single point on a student's performance timeline
 * (docs/prompts/13-evaluaciones-resultados.md §2, GET /students/{student}/
 * results). This is the exact shape the Perfil de alumno chart consumes in the
 * next session: assessment id/type, the date it was administered, and the score.
 * Assumes the parent `assessment` relation is eager-loaded.
 *
 * @mixin AssessmentResult
 */
class StudentAssessmentResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'assessment_id' => $this->assessment_id,
            'assessment_type' => $this->assessment->type->value,
            'administered_at' => $this->assessment->administered_at?->toDateString(),
            'score' => $this->score,
        ];
    }
}
