<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an accommodation (docs/prompts/18-ajustes-categoria-instancia.md §1).
 * `category` stays required on edit too (the spec's §3 test: 422 without it);
 * the remaining fields are `sometimes` so a partial patch is allowed.
 *
 * Authorization reuses the existing AccommodationPolicy::update (shares school),
 * left untouched per the spec.
 */
class UpdateAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('accommodation'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(AccommodationCategory::values())],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'focus_area' => ['sometimes', 'required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'requires_external_approval' => ['sometimes', 'boolean'],
            'llm_rule' => ['nullable', 'array'],
        ];
    }
}
