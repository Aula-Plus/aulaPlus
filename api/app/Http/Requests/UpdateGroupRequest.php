<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'level' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Once chosen, a level can be changed but not emptied again.
            'education_level_id' => ['sometimes', 'required', 'integer', Rule::exists('education_levels', 'id')],
            'school_grade_id' => ['sometimes', 'nullable', 'integer', Rule::exists('school_grades', 'id')],
            'school_year' => ['sometimes', 'integer'],
            'group_profile' => ['sometimes', 'nullable', 'array'],
            'related_documents' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
