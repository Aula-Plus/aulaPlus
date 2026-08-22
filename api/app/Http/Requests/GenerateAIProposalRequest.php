<?php

namespace App\Http\Requests;

use App\Enums\AIProposalType;
use App\Enums\AssessmentType;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates POST /groups/{group}/assistant/generate.
 *
 * Design note: the spec asks for "a specific Form Request per type". Laravel
 * resolves a FormRequest by dependency injection BEFORE the body can be read,
 * so a different class can't be type-hinted per `type`. The clean way to get
 * strict, per-type validation is a single class whose rules() builds the
 * `parameters.*` ruleset from `$this->input('type')` — explicit, complete
 * rules per branch, never a loose `parameters => array`.
 *
 * Authorization delegates to AIProposalPolicy::generate (same Sesión 2 rule as
 * creating the real entity), never a parallel rule. Every referenced id is
 * additionally constrained to the route group's own school/group so a guessed
 * id from another tenant can't leak data in (CLAUDE.md security rules 3 and 4).
 */
class GenerateAIProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('generate', [AIProposal::class, $this->group()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'type' => ['required', new Enum(AIProposalType::class)],
            'parameters' => ['required', 'array'],
            'parameters.reference_proposal_id' => [
                'nullable',
                Rule::exists('ai_proposals', 'id')->where('school_id', $this->user()->school_id),
            ],
        ], $this->parameterRules());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function parameterRules(): array
    {
        return match (AIProposalType::tryFrom((string) $this->input('type'))) {
            AIProposalType::AnnualPlan => [
                'parameters.curricular_framework_id' => ['required', $this->frameworkBelongsToGroup()],
                'parameters.subject' => ['required', 'string'],
                'parameters.year' => ['required', 'integer'],
                'parameters.student_id' => ['nullable', $this->studentBelongsToGroup()],
                'parameters.focus' => ['nullable', 'string'],
                'parameters.language' => ['nullable', 'string'],
            ],
            AIProposalType::Unit => [
                'parameters.annual_plan_id' => [
                    'required',
                    Rule::exists('annual_plans', 'id')
                        ->where('school_id', $this->user()->school_id)
                        ->where('group_id', $this->group()->id),
                ],
                'parameters.focus' => ['required', 'string'],
                'parameters.target_sessions' => ['nullable', 'integer'],
                'parameters.duration_weeks' => ['nullable', 'integer'],
            ],
            AIProposalType::ClassSession => [
                'parameters.unit_id' => ['nullable', $this->unitBelongsToGroup()],
                'parameters.focus' => ['required', 'string'],
                'parameters.duration_minutes' => ['nullable', 'integer'],
            ],
            AIProposalType::Assessment => [
                'parameters.focus' => ['required', 'string'],
                'parameters.assessment_type' => ['required', new Enum(AssessmentType::class)],
                'parameters.duration_minutes' => ['nullable', 'integer'],
            ],
            default => [],
        };
    }

    /**
     * The framework must be one linked to the route group (group_curricular_
     * framework) — not just any global framework id.
     */
    protected function frameworkBelongsToGroup(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! $this->group()->curricularFrameworks()->whereKey($value)->exists()) {
                $fail('The selected curricular framework is not available for this group.');
            }
        };
    }

    /**
     * The student must currently or historically belong to the route group and
     * the same school.
     */
    protected function studentBelongsToGroup(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! $this->group()->students()->where('students.id', $value)->exists()) {
                $fail('The selected student does not belong to this group.');
            }
        };
    }

    /**
     * A Unit has no school_id/group_id of its own — it inherits both through
     * its AnnualPlan (already tenant-scoped), which must point at the route
     * group.
     */
    protected function unitBelongsToGroup(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            $belongs = Unit::query()
                ->whereKey($value)
                ->whereHas('annualPlan', fn ($query) => $query->where('group_id', $this->group()->id))
                ->exists();

            if (! $belongs) {
                $fail('The selected unit does not belong to this group.');
            }
        };
    }

    protected function group(): Group
    {
        /** @var Group */
        return $this->route('group');
    }
}
