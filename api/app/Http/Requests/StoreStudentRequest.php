<?php

namespace App\Http\Requests;

use App\Enums\StudentStatus;
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'group_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'family_contact_name' => ['nullable', 'string', 'max:255'],
            'family_contact_phone' => ['nullable', 'string', 'max:50'],
            'family_contact_email' => ['nullable', 'email', 'max:255'],
            'pedagogical_notes' => ['nullable', 'string'],
        ];
    }
}
