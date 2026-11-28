<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Enums\Role;
use App\Http\Requests\Concerns\ValidatesAccommodationScope;
use App\Models\Accommodation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an accommodation (docs/prompts/18-ajustes-categoria-instancia.md §1).
 * `category` stays required on edit too (the spec's §3 test: 422 without it);
 * the remaining fields are `sometimes` so a partial patch is allowed.
 *
 * Authorization reuses AccommodationPolicy::update: same school AND
 * psychopedagogy, or direction on its proposal still under review; a teacher
 * only their own open proposal (ClickUp 86e3dpbwy follow-up, 86e3qkwkk,
 * 1dfd3mc55zu).
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
        $isPsychopedagogue = $this->user()->hasRole(Role::Psychopedagogue->value);

        return [
            'category' => ['required', Rule::in(AccommodationCategory::values())],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'focus_area' => ['sometimes', 'required', 'string', 'max:255'],
            // Direction only edits its own proposals while under review; it
            // never switches an accommodation on or off (ClickUp 86e3qkwkk).
            'active' => $isPsychopedagogue ? ['sometimes', 'boolean'] : ['prohibited'],
            // «Origen» is edited by psychopedagogy only (ClickUp 86e3qkwkk).
            'origin' => $isPsychopedagogue ? ['sometimes', 'nullable', 'string', 'max:255'] : ['prohibited'],
            'llm_rule' => ['nullable', 'array'],
            ...$this->teacherScopedRules($this->accommodationScopeRules(editing: $this->route('accommodation'))),
            // «Vigente desde» of a proposal is psychopedagogy's to set when it
            // confirms it; direction never sends it (same rule as the screen).
            ...($isPsychopedagogue ? [] : ['starts_on' => ['prohibited']]),
        ];
    }

    /**
     * A teacher resending their proposal keeps to the subjects they give the
     * student (answer 2B, ClickUp 1dfd3mc55zu), plus the ones it already has.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function teacherScopedRules(array $rules): array
    {
        $user = $this->user();
        $accommodation = $this->route('accommodation');

        if (! $user->hasAnyRole(Role::schoolWideValues()) && $accommodation instanceof Accommodation && $accommodation->student !== null) {
            $attached = $accommodation->subjects()->pluck('subjects.id')->map(fn ($id) => (int) $id)->all();
            $rules['subject_ids.*'][] = $this->taughtSubjectRule($user, $accommodation->student, $attached);
        }

        return $rules;
    }
}
