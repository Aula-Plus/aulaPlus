<?php

namespace App\Http\Resources;

use App\Enums\AIProposalType;
use App\Models\AIProposal;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\UnitObjective;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AIProposal
 */
class AIProposalResource extends JsonResource
{
    /**
     * `context_sent` is deliberately NOT exposed: it is an internal audit/debug
     * snapshot, not something the polling frontend needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'requested_by_id' => $this->requested_by_id,
            'journey_id' => $this->journey_id,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'input_parameters' => $this->input_parameters,
            'raw_response' => $this->raw_response,
            'error_message' => $this->error_message,
            'applied_to_id' => $this->applied_to_id,
            'applied_to_type' => $this->applied_to_type,
            // What screen 15 shows above a class draft, and whether the viewer
            // decides on it (only the requester; Psicopedagogía and Dirección
            // read it, P15-DEC-08).
            'class_request' => $this->type === AIProposalType::ClassSession ? $this->classRequest() : null,
            'can_decide' => $request->user()?->can('apply', $this->resource) ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Group, subject and unit of a class request, resolved by name, plus the
     * unit objectives the teacher chose. Everything is read through the
     * tenant-scoped models.
     *
     * @return array<string, mixed>
     */
    protected function classRequest(): array
    {
        $parameters = $this->input_parameters ?? [];
        $unit = isset($parameters['unit_id']) ? Unit::query()->with('annualPlan')->find($parameters['unit_id']) : null;
        $subjectId = $parameters['subject_id'] ?? $unit?->annualPlan?->subject_id;
        $subject = $subjectId !== null ? Subject::query()->find($subjectId) : null;
        $group = $this->group;
        $objectiveIds = array_map('intval', (array) ($parameters['unit_objective_ids'] ?? []));

        return [
            'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'subject' => $subject ? ['id' => $subject->id, 'name' => $subject->name] : null,
            'unit' => $unit ? [
                'id' => $unit->id,
                'name' => $unit->name,
                'position' => $unit->position,
                'annual_plan_id' => $unit->annual_plan_id,
            ] : null,
            'unit_objectives' => $unit === null || $objectiveIds === [] ? [] : UnitObjective::query()
                ->where('unit_id', $unit->id)
                ->whereKey($objectiveIds)
                ->orderBy('position')
                ->get(['id', 'description'])
                ->map(fn (UnitObjective $objective): array => ['id' => $objective->id, 'description' => $objective->description])
                ->all(),
        ];
    }
}
