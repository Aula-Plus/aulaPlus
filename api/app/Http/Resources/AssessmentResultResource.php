<?php

namespace App\Http\Resources;

use App\Models\AssessmentResult;
use App\Models\School;
use App\Services\ResultScales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's result on an assessment (docs/prompts/13-evaluaciones-
 * resultados.md §2). Used by the per-assessment results endpoints.
 *
 * A Primaria result carries `scale_level` (the level of the official scale)
 * instead of `score`, and `numeric_grade` / `numeric_grade_source` follow the
 * school's switch and equivalence (App\Services\ResultScales::numericGradeFor):
 * with the switch off no number is returned at all.
 *
 * @mixin AssessmentResult
 */
class AssessmentResultResource extends JsonResource
{
    /** @var array{value: int|null, source: 'school'|'teacher'|null}|null */
    public ?array $numeric = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $numeric = $this->numericGrade();

        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'student_id' => $this->student_id,
            'score' => $this->score,
            'scale_level' => $this->scaleLevel ? [
                'id' => $this->scaleLevel->id,
                'position' => $this->scaleLevel->position,
                'name' => $this->scaleLevel->name,
            ] : null,
            'numeric_grade' => $numeric['value'],
            'numeric_grade_source' => $numeric['source'],
            'feedback' => $this->feedback,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The number shown next to the level. The controller sets it for a whole
     * batch (one read of the school's settings); a lone resource resolves it.
     *
     * @return array{value: int|null, source: 'school'|'teacher'|null}
     */
    protected function numericGrade(): array
    {
        if ($this->numeric !== null) {
            return $this->numeric;
        }

        if ($this->official_scale_level_id === null) {
            return ['value' => null, 'source' => null];
        }

        $scales = app(ResultScales::class);
        $school = School::query()->findOrFail($this->school_id);

        return $scales->numericGradeFor($this->resource, $school, $scales->equivalences($school));
    }
}
