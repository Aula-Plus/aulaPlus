<?php

namespace App\Http\Requests;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Subject;
use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return [
            'type' => ['sometimes', 'required', new Enum(AssessmentType::class)],
            'purpose' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'administered_at' => ['sometimes', 'required', 'date'],
            'subject_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId()),
                // The new subject must be one the teacher is assigned in the
                // assessment's own group (not the request-supplied group).
                function (string $attribute, mixed $value, Closure $fail) use ($assessment): void {
                    $subject = Subject::find($value);
                    if ($subject === null || ! $this->user()->teachesSubjectInGroup($assessment->group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');
                    }
                },
            ],
        ];
    }
}
