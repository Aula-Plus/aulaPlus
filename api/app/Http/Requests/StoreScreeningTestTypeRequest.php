<?php

namespace App\Http\Requests;

use App\Enums\SchoolLevel;
use App\Enums\ScreeningFrequency;
use App\Models\ScreeningTestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /screening-test-types. Authorization delegates to
 * ScreeningTestTypePolicy::create (psychopedagogy only) — never a parallel
 * rule. The general data of screen 08 (population, frequency, teacher access)
 * is optional at creation and editable later.
 */
class StoreScreeningTestTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ScreeningTestType::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            ...self::generalDataRules(),
        ];
    }

    /**
     * Population, frequency and teacher access, shared with the update request.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function generalDataRules(): array
    {
        return [
            'levels' => ['sometimes', 'nullable', 'array'],
            'levels.*' => ['distinct', Rule::enum(SchoolLevel::class)],
            'frequency' => ['sometimes', 'nullable', Rule::enum(ScreeningFrequency::class)],
            'frequency_years' => [
                'nullable',
                'integer',
                'min:2',
                'max:10',
                'required_if:frequency,'.ScreeningFrequency::EveryFewYears->value,
            ],
            'teacher_visible' => ['sometimes', 'boolean'],
        ];
    }
}
