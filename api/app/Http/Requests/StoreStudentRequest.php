<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Student::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'photo_url' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'enrollment_year' => ['required', 'integer'],
            'has_therapeutic_companion' => ['sometimes', 'boolean'],
            'learning_profile' => ['nullable', 'array'],
            'tracking_notes' => ['nullable', 'string'],
            'individual_profile' => ['nullable', 'array'],
            'related_documents' => ['nullable', 'array'],
            'group_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
            'school_year' => ['required_with:group_id', 'integer'],
        ];
    }
}
