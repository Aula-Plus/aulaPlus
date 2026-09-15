<?php

namespace App\Http\Requests;

use App\Models\Subject;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return $this->user()->can('update', $subject);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('subjects', 'name')
                    ->where('school_id', Tenancy::schoolId())
                    ->whereNull('deleted_at')
                    ->ignore($subject->id),
            ],
            'short_code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:32'],
        ];
    }
}
