<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /groups/{group}/level — choose or change a group's level and grade
 * (GroupPolicy::setLevel: direction, or psychopedagogy when direction allowed
 * it). Same rules as on create: level mandatory, grade optional.
 */
class UpdateGroupLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setLevel', $this->route('group'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'education_level_id' => ['required', 'integer', Rule::exists('education_levels', 'id')],
            'school_grade_id' => ['nullable', 'integer', Rule::exists('school_grades', 'id')],
        ];
    }
}
