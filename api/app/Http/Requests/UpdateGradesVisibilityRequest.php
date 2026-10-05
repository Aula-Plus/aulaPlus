<?php

namespace App\Http\Requests;

use App\Enums\GradesVisibility;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Direction chooses, for the whole school, what a teacher sees of grades in
 * subjects they do not teach.
 */
class UpdateGradesVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(Role::Director->value);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(GradesVisibility::class)],
            'cutoff_months' => [
                Rule::requiredIf(fn () => $this->input('mode') === GradesVisibility::AllPeriodic->value),
                'nullable', 'integer', 'min:1', 'max:60',
            ],
            'cutoff_anchor' => ['nullable', 'date'],
        ];
    }
}
