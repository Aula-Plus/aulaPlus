<?php

namespace App\Http\Requests;

use App\Enums\AIRequestType;
use App\Models\EducationLevel;
use App\Models\School;
use App\Models\SchoolAIInstruction;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Direction adds a rule to the school's layer of AI instructions (screen 24,
 * ClickUp 1dfd3mc4bh6): the institution-wide one (no level) or one for a level
 * or a range of its grades (P24-DEC-02). Only judgments and assessments are
 * configurable today. Two rules of the same type for exactly the same scope
 * are a 422: «gana la más específica» needs one rule per scope.
 */
class StoreSchoolAIInstructionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $school = School::query()->find($this->user()->school_id);

        return $school !== null && $this->user()->can('manageAIInstructions', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'request_type' => ['required', 'string', Rule::in(AIRequestType::configurableValues())],
            'education_level_id' => ['nullable', 'integer', Rule::exists('education_levels', 'id')],
            'school_grade_ids' => ['nullable', 'array', 'max:30'],
            'school_grade_ids.*' => ['integer', 'distinct'],
            ...SchoolAIInstructionSettingsRules::for($this->input('request_type')),
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $levelId = $this->input('education_level_id');
                $grades = $this->gradeIds();

                if ($levelId === null && $grades !== []) {
                    $validator->errors()->add('school_grade_ids', 'Grades need a level.');

                    return;
                }

                if ($levelId !== null) {
                    $levelGrades = EducationLevel::query()->find($levelId)?->grades->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
                    if (array_diff($grades, $levelGrades) !== []) {
                        $validator->errors()->add('school_grade_ids', 'Every grade must belong to the level.');

                        return;
                    }
                }

                $duplicate = SchoolAIInstruction::query()
                    ->where('request_type', $this->input('request_type'))
                    ->where('education_level_id', $levelId)
                    ->get()
                    ->contains(function (SchoolAIInstruction $rule) use ($grades): bool {
                        $existing = $rule->gradeIds();
                        sort($existing);

                        return $existing === $grades;
                    });

                if ($duplicate) {
                    $validator->errors()->add('education_level_id', 'There is already a rule for this level or range.');
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function gradeIds(): array
    {
        $grades = array_values(array_unique(array_map('intval', (array) ($this->input('school_grade_ids') ?? []))));
        sort($grades);

        return $grades;
    }
}
