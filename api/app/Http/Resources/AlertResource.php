<?php

namespace App\Http\Resources;

use App\Models\Alert;
use App\Models\AlertAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * Relations every alert list eager-loads so the resource renders without
     * N+1 queries (subject, responsible person, thread).
     */
    public const RELATIONS = [
        'subject:id,name',
        'assignee:id,name',
        'outcomeBy:id,name',
        'actions.actor:id,name',
        'actions.assignee:id,name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'student_name' => $this->whenLoaded('student', fn () => $this->student?->full_name),
            'subject_id' => $this->subject_id,
            'subject_name' => $this->whenLoaded('subject', fn () => $this->subject?->name),
            'source_alert_id' => $this->source_alert_id,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'description' => $this->description,
            'condition_met_on' => $this->condition_met_on?->toDateString(),
            'recipients' => $this->recipients,
            // The way out chosen (ClickUp 86e3jpzdp): who has it, since when,
            // and until when — «la tiene X desde el dd/mm».
            'outcome' => $this->outcome?->value,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee
                ? ['id' => $this->assignee->id, 'name' => $this->assignee->name]
                : null),
            'outcome_by' => $this->whenLoaded('outcomeBy', fn () => $this->outcomeBy
                ? ['id' => $this->outcomeBy->id, 'name' => $this->outcomeBy->name]
                : null),
            'outcome_at' => $this->outcome_at?->toIso8601String(),
            'due_on' => $this->due_on?->toDateString(),
            'is_overdue' => ! $this->resolved && $this->due_on !== null && $this->due_on->lt(today()),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn (AlertAction $action) => [
                'id' => $action->id,
                'outcome' => $action->outcome->value,
                'actor' => ['id' => $action->actor_id, 'name' => $action->actor?->name],
                'assignee' => ['id' => $action->assignee_id, 'name' => $action->assignee?->name],
                'due_on' => $action->due_on?->toDateString(),
                'note' => $action->note,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->values()),
            'can' => $this->when($user !== null, fn () => [
                'act' => $this->resource->canBeActedOnBy($user),
                'resolve' => $this->resource->canBeResolvedBy($user),
            ]),
            'resolved' => $this->resolved,
            'resolved_by_id' => $this->resolved_by_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
