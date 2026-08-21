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
            'school_year' => ['sometimes', 'integer'],
            'group_profile' => ['sometimes', 'nullable', 'array'],
            'related_documents' => ['sometimes', 'nullable', 'array'],
            'teacher_ids' => ['sometimes', 'array'],
            'teacher_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
        ];
    }
}
