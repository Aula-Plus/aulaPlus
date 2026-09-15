<?php

namespace App\Http\Requests;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\Group;
use App\Models\Subject;
use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        /** @var Group $group */
        $group = $this->route('group');

        return [
            'type' => ['required', new Enum(AssessmentType::class)],
            'purpose' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'administered_at' => ['required', 'date'],
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId()),
                // The teacher must actually be assigned this subject in this
                // group (single source of truth: User::teachesSubjectInGroup).
                function (string $attribute, mixed $value, Closure $fail) use ($group): void {
                    $subject = Subject::find($value);
                    if ($subject === null || ! $this->user()->teachesSubjectInGroup($group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');
                    }
                },
            ],
        ];
    }
}
