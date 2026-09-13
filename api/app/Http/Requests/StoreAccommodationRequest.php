<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Models\Accommodation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating an accommodation for a student (docs/prompts/18-ajustes-categoria-
 * instancia.md §1). Session 1 left the model, migration and
 * AccommodationPolicy but never built a create endpoint — this fills that gap,
 * and `category` is required here (nullable in the DB only so pre-existing
 * factories keep working; every new write must set it).
 *
 * Authorization reuses the existing AccommodationPolicy::create (role check
 * only) as the spec asks — it is deliberately NOT hardened further here. The
 * target {student} is resolved through the SchoolScope, so a student from
 * another tenant 404s before this runs.
 */
class StoreAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Accommodation::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(AccommodationCategory::values())],
            'type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'focus_area' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'requires_external_approval' => ['sometimes', 'boolean'],
            'llm_rule' => ['nullable', 'array'],
        ];
    }
}
