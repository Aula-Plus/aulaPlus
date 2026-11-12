<?php

namespace App\Http\Requests;

use App\Enums\AIProposalType;
use App\Enums\AssessmentType;
use App\Enums\Role;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\Subject;
use App\Models\Unit;
use Closure;
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
            // The creation journey this request belongs to (ClickUp
            // 86e3qkxtc). Retries and change requests reuse the same id.
            'journey_id' => ['nullable', 'uuid'],
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
                'parameters.subject_id' => [
                    'required',
                    Rule::exists('subjects', 'id')->where('school_id', $this->user()->school_id),
                ],
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
            // A proposal can't be requested without a subject (ClickUp
            // 86e3jpzdr, PO decision of 03/11): without one there is no
            // program, no group performance and no way to cut the group's
            // accommodations to the right subject. Annual plans and
            // assessments name it directly and a unit inherits it from its
            // plan (annual_plans.subject_id is NOT NULL); a class session gets
            // it from its unit or, when it hangs from none, chosen directly —
            // never both, so there's a single source of truth.
            AIProposalType::ClassSession => [
                'parameters.unit_id' => ['nullable', 'required_without:parameters.subject_id', $this->unitBelongsToGroup()],
                'parameters.subject_id' => [
                    'nullable',
                    'required_without:parameters.unit_id',
                    'prohibits:parameters.unit_id',
                    Rule::exists('subjects', 'id')->where('school_id', $this->user()->school_id),
                    $this->subjectTaughtInGroup(),
                ],
                'parameters.focus' => ['required', 'string'],
                'parameters.duration_minutes' => ['nullable', 'integer'],
                // Optional: the class's subject (used for the Calendar and to
                // pre-fill time/room from «Mis horarios»). Same rule as the
                // assessment subject: one the teacher teaches in the group.
                'parameters.subject_id' => [
                    'nullable',
                    Rule::exists('subjects', 'id')->where('school_id', $this->user()->school_id),
                    $this->assignedSubjectInGroup(),
                ],
            ],
            AIProposalType::Assessment => [
                'parameters.focus' => ['required', 'string'],
                'parameters.assessment_type' => ['required', new Enum(AssessmentType::class)],
                // An applied assessment proposal creates a real Assessment, which
                // now requires a subject in the school's catalog AND that the
                // teacher is assigned that subject in the group (decision C1) —
                // the same rule the direct assessment endpoint enforces, so the
                // AI path can't be used to bypass teachesSubjectInGroup.
                'parameters.subject_id' => [
                    'required',
                    Rule::exists('subjects', 'id')->where('school_id', $this->user()->school_id),
                    $this->assignedSubjectInGroup(),
                ],
                'parameters.duration_minutes' => ['nullable', 'integer'],
            ],
            default => [],
        };
    }

    /**
     * The subject must exist in the school AND the requesting teacher must be
     * assigned that subject in the route group — mirroring StoreAssessmentRequest
     * (single source of truth: User::teachesSubjectInGroup). Without this, the AI
     * assessment path could create an Assessment for a subject the teacher does
     * not teach, bypassing decision C1.
     */
    protected function assignedSubjectInGroup(): callable
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $subject = Subject::find($value);
            if ($subject === null || ! $this->user()->teachesSubjectInGroup($this->group(), $subject)) {
                $fail('You are not assigned to teach this subject in this group.');
            }
        };
    }

    /**
     * A subject chosen directly for a class session must be taught in the
     * route group. A teacher may only pick one they teach there themselves
     * (User::teachesSubjectInGroup, as for assessments); psychopedagogy and
     * direction, who aren't assigned subjects, any subject someone teaches in
     * the group.
     */
    protected function subjectTaughtInGroup(): callable
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $subject = Subject::find($value);
            if ($subject === null) {
                return;
            }

            $allowed = $this->user()->hasAnyRole(Role::schoolWideValues())
                ? $this->group()->teachers()->wherePivot('subject_id', $subject->id)->exists()
                : $this->user()->teachesSubjectInGroup($this->group(), $subject);

            if (! $allowed) {
                $fail('The selected subject is not taught in this group.');
            }
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
