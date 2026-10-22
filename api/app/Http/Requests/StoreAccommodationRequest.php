<?php

namespace App\Http\Requests;

use App\Enums\AccommodationCategory;
use App\Enums\Role;
use App\Http\Requests\Concerns\ValidatesAccommodationScope;
use App\Models\Accommodation;
use App\Models\User;
use App\Services\Accommodations\AccommodationProposalWorkflow;
use App\Support\SchoolTime;
use Closure;
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
 * another tenant 404s before this runs. `starts_on` is optional (the model
 * defaults it to today, school time); subjects and
 * assessment types are optional (absent = general) — see
 * ValidatesAccommodationScope. What direction creates is a proposal
 * psychopedagogy must confirm (ClickUp 86e3qkwkk; the controller decides,
 * never the payload). `requires_external_approval` is no longer accepted.
 *
 * Direction, when proposing, picks the review deadline (`review_due_date`) and
 * who reviews it: the whole psychopedagogy role, or one or more specific
 * psychopedagogues (`reviewer_ids`). «Origen» is psychopedagogy's to write:
 * direction never sends it (ClickUp 86e3qkwkk, 21/10 answer).
 */
class StoreAccommodationRequest extends FormRequest
{
    use ValidatesAccommodationScope;

    public function authorize(): bool
    {
        return $this->user()->can('create', Accommodation::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $proposes = AccommodationProposalWorkflow::createsProposals($this->user());

        return [
            'category' => ['required', Rule::in(AccommodationCategory::values())],
            'type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'focus_area' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'origin' => $proposes ? ['prohibited'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'llm_rule' => ['nullable', 'array'],
            ...$this->accommodationScopeRules(),
            ...($proposes ? $this->reviewRules() : [
                'review_due_date' => ['prohibited'],
                'review_recipients' => ['prohibited'],
                'reviewer_ids' => ['prohibited'],
            ]),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function reviewRules(): array
    {
        $schoolId = $this->user()->school_id;

        return [
            'review_due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.SchoolTime::today()->toDateString()],
            'review_recipients' => ['required', Rule::in(['role', 'people'])],
            'reviewer_ids' => ['exclude_unless:review_recipients,people', 'required', 'array', 'min:1'],
            'reviewer_ids.*' => [
                'integer',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($schoolId): void {
                    $isReviewer = User::query()
                        ->whereKey($value)
                        ->where('school_id', $schoolId)
                        ->whereNull('disabled_at')
                        ->role(Role::Psychopedagogue->value)
                        ->exists();

                    if (! $isReviewer) {
                        $fail('The selected reviewer is not an active psychopedagogue of this school.');
                    }
                },
            ],
        ];
    }
}
