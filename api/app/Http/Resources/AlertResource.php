<?php

namespace App\Http\Resources;

use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\ScheduledFollowUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * Relations every alert list eager-loads so the resource renders without
     * N+1 queries (subject, responsible person, thread, linked follow-ups).
     */
    public const RELATIONS = [
        'subject:id,name',
        'commentCategory:id,name',
        'assignee:id,name',
        'outcomeBy:id,name',
        'actions.actor:id,name',
        'actions.assignee:id,name',
        'followUps:id,alert_id,student_id,due_date,resolved',
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
            // «Señal de dificultad» alerts (ClickUp 86e3qkwpn): the category only.
            'comment_category_id' => $this->comment_category_id,
            'comment_category_name' => $this->whenLoaded('commentCategory', fn () => $this->commentCategory?->name),
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
            // «Llegó a su fecha sin salida» (review of 14/10): when the daily
            // run found the current deadline passed with the alert pending.
            // The same alert — no second one is created.
            'overdue_at' => $this->overdue_at?->toIso8601String(),
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn (AlertAction $action) => [
                'id' => $action->id,
                'kind' => $action->kind->value,
                'outcome' => $action->outcome?->value,
                // `null` on an `overdue` step: written by the daily run.
                'actor' => $action->actor_id !== null
                    ? ['id' => $action->actor_id, 'name' => $action->actor?->name]
                    : null,
                'assignee' => $action->assignee_id !== null
                    ? ['id' => $action->assignee_id, 'name' => $action->assignee?->name]
                    : null,
                'due_on' => $action->due_on?->toDateString(),
                'note' => $action->note,
                'created_at' => $action->created_at?->toIso8601String(),
            ])->values()),
            // Follow-ups created from this alert («Crear seguimiento
            // vinculado»): state and deadline only — their content is read
            // through the follow-up endpoints. Everyone who sees an alert sees
            // its student (Alert::scopeVisibleTo), so they may see these too.
            'follow_ups' => $this->whenLoaded('followUps', fn () => $this->followUps
                ->sortBy('due_date')
                ->map(fn (ScheduledFollowUp $followUp) => [
                    'id' => $followUp->id,
                    'due_date' => $followUp->due_date?->toDateString(),
                    'resolved' => $followUp->resolved,
                    'is_overdue' => $followUp->isOverdue(),
                ])->values()),
            // Whether the viewer has the alert now (decision of 21/10, option
            // A: each one reads their own situation): for whoever received it
            // it is «a tu cargo», never «pasada a otro rol».
            'is_in_charge' => $this->when($user !== null, fn () => $this->resource->isInChargeBy($user)),
            'can' => $this->when($user !== null, fn () => [
                'act' => $this->resource->canBeActedOnBy($user),
                'note' => $this->resource->canBeNotedBy($user),
                'resolve' => $this->resource->canBeResolvedBy($user),
                'link_follow_up' => $this->resource->canLinkFollowUpBy($user),
            ]),
            'resolved' => $this->resolved,
            'resolved_by_id' => $this->resolved_by_id,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
