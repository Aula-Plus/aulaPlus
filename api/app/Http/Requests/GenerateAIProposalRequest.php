<?php

namespace App\Http\Requests;

use App\Enums\AIProposalType;
use App\Enums\AssessmentType;
use App\Enums\ClassSection;
use App\Models\AIProposal;
use App\Models\Group;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Services\CurricularStructure\CurricularOfferings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

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
        // Only the teacher generates classes (P14-DEC-04, P14-DEC-61):
        // Psicopedagogía and Dirección read and comment the proposal.
        if ($this->input('type') === AIProposalType::ClassSession->value) {
            return $this->user()->can('generateClass', [AIProposal::class, $this->group()]);
        }

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
            //
            // Screen 14 (ClickUp 86e3jujh0, P14-DEC-01): from a unit, its
            // plan fixes group and subject; entering directly, the subject is
            // chosen and the unit is optional — a free class reads no planner
            // and adds nothing to coverage. Either way the subject must be
            // one the requester teaches in the group (PA-104).
            AIProposalType::ClassSession => [
                'parameters.unit_id' => ['nullable', 'integer', 'required_without:parameters.subject_id', $this->unitBelongsToGroup()],
                'parameters.subject_id' => [
                    'nullable',
                    'integer',
                    'required_without:parameters.unit_id',
                    'prohibits:parameters.unit_id',
                    Rule::exists('subjects', 'id')->where('school_id', $this->user()->school_id),
                    $this->subjectTaughtInGroup(),
                ],
                // «Tema» of screen 14.
                'parameters.focus' => ['required', 'string', 'max:300'],
                // «Qué querés que logren»: the teacher's own objectives.
                'parameters.objectives' => ['nullable', 'string', 'max:2000'],
                // Objectives of the unit chosen to work in this class.
                'parameters.unit_objective_ids' => ['nullable', 'array', 'max:20', 'prohibits:parameters.subject_id'],
                'parameters.unit_objective_ids.*' => ['integer', 'distinct', $this->objectiveBelongsToUnit()],
                'parameters.date' => ['nullable', 'date_format:Y-m-d'],
                'parameters.duration_minutes' => ['nullable', 'integer', 'min:10', 'max:300'],
                'parameters.requirements' => ['nullable', 'string', 'max:2000'],
                // «Quiero cambiar algo» / regenerate: the sections to redo of
                // an earlier draft of the same requester, in one call.
                'parameters.regenerate_sections' => ['nullable', 'array'],
                'parameters.regenerate_sections.*' => ['string', 'distinct', Rule::in(ClassSection::values())],
                'parameters.change_requests' => ['nullable', 'array'],
                'parameters.change_requests.*' => ['nullable', 'string', 'max:1000'],
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
     * A subject chosen directly for a class session must be one the
     * requesting teacher teaches in the route group (User::
     * teachesSubjectInGroup, as for assessments; PA-104). Only teachers get
     * here (AIProposalPolicy::generateClass).
     */
    protected function subjectTaughtInGroup(): callable
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $subject = Subject::find($value);
            if ($subject === null) {
                return;
            }

            if (! $this->user()->teachesSubjectInGroup($this->group(), $subject)) {
                $fail('The selected subject is not taught in this group.');
            }
        };
    }

    /**
     * A chosen unit objective must be one of the requested unit's.
     */
    protected function objectiveBelongsToUnit(): callable
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $unitId = $this->input('parameters.unit_id');
            $belongs = is_numeric($unitId) && UnitObjective::query()
                ->whereKey($value)
                ->where('unit_id', (int) $unitId)
                ->exists();

            if (! $belongs) {
                $fail('The selected objective does not belong to this unit.');
            }
        };
    }

    /**
     * For a class: a unit's plan subject must be one the requester teaches in
     * the group (a co-teacher of the subject may plan from a colleague's
     * unit, P14-DEC-61), and a regeneration must point at an earlier class
     * draft of the same requester and group.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('type') !== AIProposalType::ClassSession->value) {
                    return;
                }

                $unitId = $this->input('parameters.unit_id');
                if ($unitId !== null) {
                    $subject = Unit::query()->find($unitId)?->annualPlan?->subject;
                    if ($subject === null || ! $this->user()->teachesSubjectInGroup($this->group(), $subject)) {
                        $validator->errors()->add('parameters.unit_id', 'You do not teach the subject of this unit in this group.');
                    }
                }

                if ($this->filled('parameters.regenerate_sections')) {
                    $reference = AIProposal::query()->find($this->input('parameters.reference_proposal_id'));
                    if ($reference === null
                        || $reference->type !== AIProposalType::ClassSession
                        || $reference->requested_by_id !== $this->user()->id
                        || $reference->group_id !== $this->group()->id
                        || $reference->raw_response === null) {
                        $validator->errors()->add('parameters.reference_proposal_id', 'Regenerating needs an earlier class draft of yours for this group.');
                    }
                }
            },
        ];
    }

    /**
     * The framework must be one linked to the route group (group_curricular_
     * framework) — not just any global framework id.
     */
    protected function frameworkBelongsToGroup(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            // The group's frameworks, or one of the programs the school set
            // for this group-subject in «Oferta curricular» (screen 23).
            $subject = Subject::query()->find((int) $this->input('parameters.subject_id'));
            $offered = $subject !== null
                && app(CurricularOfferings::class)->effectiveIdsFor($this->group(), $subject)->contains((int) $value);

            if (! $offered && ! $this->group()->curricularFrameworks()->whereKey($value)->exists()) {
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
