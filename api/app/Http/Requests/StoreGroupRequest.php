<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Group::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:255'],
            'school_year' => ['required', 'integer'],
            'group_profile' => ['nullable', 'array'],
            'related_documents' => ['nullable', 'array'],
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
