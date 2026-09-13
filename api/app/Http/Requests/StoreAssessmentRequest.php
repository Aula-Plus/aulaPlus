<?php

namespace App\Http\Requests;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Creating an assessment for a group (docs/prompts/13-evaluaciones-
 * resultados.md §1). Authorization is two-layered on purpose:
 *
 * - AssessmentPolicy::create only checks the *role* (teacher), not the relation
 *   to this specific group — so we additionally require that the authenticated
 *   teacher actually leads the target group ($user->teachesGroup($group)), the
 *   same helper every other policy uses. Per the spec this group-specific check
 *   lives here (the controller/request), NOT in the Session 2 policy, which we
 *   deliberately leave untouched.
 */
class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Group $group */
        $group = $this->route('group');

        return $this->user()->can('create', Assessment::class)
            && $this->user()->teachesGroup($group);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(AssessmentType::class)],
            'purpose' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'administered_at' => ['required', 'date'],
        ];
    }
}
