<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Enums\Role;
use App\Http\Requests\Concerns\ValidatesAccommodationScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an accommodation (docs/prompts/18-ajustes-categoria-instancia.md §1).
 * `category` stays required on edit too (the spec's §3 test: 422 without it);
 * the remaining fields are `sometimes` so a partial patch is allowed.
 *
 * Authorization reuses AccommodationPolicy::update: same school AND
 * psychopedagogy, or direction on a proposal still under review — a teacher
 * never edits one (ClickUp 86e3dpbwy follow-up, 86e3qkwkk).
 * `requires_external_approval` is no longer accepted (ClickUp 86e3qkwkk). The scope fields (start date, subjects,
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
            // Direction only edits its own proposals while under review; it
            // never switches an accommodation on or off (ClickUp 86e3qkwkk).
            'active' => $this->user()->hasRole(Role::Psychopedagogue->value)
                ? ['sometimes', 'boolean']
                : ['prohibited'],
            'llm_rule' => ['nullable', 'array'],
            ...$this->accommodationScopeRules(editing: $this->route('accommodation')),
        ];
    }
}
