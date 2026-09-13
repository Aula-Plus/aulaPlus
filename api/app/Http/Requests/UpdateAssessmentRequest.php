<?php

namespace App\Http\Requests;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Updating an assessment (docs/prompts/13-evaluaciones-resultados.md §1).
 * Only the owning teacher may edit, enforced by AssessmentPolicy::update
 * (Session 2). `content` and the CurricularItem link stay out of scope here —
 * they belong to Session 5's curricular flow.
 */
class UpdateAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $this->user()->can('update', $assessment);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', new Enum(AssessmentType::class)],
            'purpose' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'administered_at' => ['sometimes', 'required', 'date'],
        ];
    }
}
