<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Http\Requests\Concerns\ValidatesAccommodationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an accommodation (docs/prompts/18-ajustes-categoria-instancia.md §1).
 * `category` stays required on edit too (the spec's §3 test: 422 without it);
 * the remaining fields are `sometimes` so a partial patch is allowed.
 *
 * Authorization reuses AccommodationPolicy::update: same school AND a
 * school-wide role (psychopedagogy/direction) — a teacher never edits one
 * (ClickUp 86e3dpbwy follow-up). The scope fields (start date, subjects,
 * assessment types) come from ValidatesAccommodationScope.
 */
class UpdateAccommodationRequest extends FormRequest
{
    use ValidatesAccommodationScope;

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
            ...$this->accommodationScopeRules(editing: $this->route('accommodation')),
        ];
    }
}
