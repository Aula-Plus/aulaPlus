<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upserting a batch of results for one assessment (docs/prompts/13-
 * evaluaciones-resultados.md §2). Only the teacher who owns the parent
 * assessment may write results, enforced via AssessmentResultPolicy::create
 * (which mirrors AssessmentPolicy::update).
 *
 * Each student_id must belong to the assessment's group. A student has no
 * direct group_id — membership lives in the historized group_student pivot —
 * so we check it through the relation, exactly as the spec prescribes.
 */
class StoreAssessmentResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $this->user()->can('create', [AssessmentResult::class, $assessment]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1'],
            'results.*.student_id' => ['required', 'integer', $this->studentBelongsToGroup()],
            'results.*.score' => ['required', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
            'results.*.feedback' => ['nullable', 'string'],
        ];
    }

    /**
     * Reject (422) any student_id that isn't enrolled in the assessment's group.
     */
    protected function studentBelongsToGroup(): Closure
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return function (string $attribute, mixed $value, Closure $fail) use ($assessment): void {
            $belongs = $assessment->group()
                ->first()
                ?->students()
                ->whereKey($value)
                ->exists();

            if (! $belongs) {
                $fail('El alumno no pertenece al grupo de la evaluación.');
            }
        };
    }
}
