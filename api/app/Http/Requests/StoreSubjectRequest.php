<?php

namespace App\Http\Requests;

use App\Models\Subject;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Subject::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                // Unique per school (matches the DB unique index).
                Rule::unique('subjects', 'name')
                    ->where('school_id', Tenancy::schoolId())
                    ->whereNull('deleted_at'),
            ],
            'short_code' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:32'],
        ];
    }
}
