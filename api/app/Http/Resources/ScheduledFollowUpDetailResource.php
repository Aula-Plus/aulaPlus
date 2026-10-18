<?php

namespace App\Http\Resources;

use App\Enums\FollowUpMemberRole;
use App\Enums\FollowUpStageStatus;
use App\Models\FollowUpEvent;
use App\Models\FollowUpMember;
use App\Models\FollowUpStage;
use App\Models\FollowUpStageNote;
use App\Models\ScheduledFollowUp;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The full record of a follow-up as one viewer sees it (ClickUp 86e3qkwna):
 * stages with their notes, people, history, and `can` flags computed by
 * ScheduledFollowUpPolicy for this viewer. The flags only drive the UI — every
 * mutation is authorized again on the server.
 *
 * Someone who withdrew or was removed sees the follow-up as it was when they
 * left: history and notes up to that moment, only the stages they took part
 * in (responsible, creator or note author), and later closures/resolution as
 * not yet happened. See {@see self::forViewer()}.
 *
 * @mixin ScheduledFollowUp
 */
class ScheduledFollowUpDetailResource extends ScheduledFollowUpResource
{
    protected User $viewer;

    protected ?Carbon $cutoff = null;

    public static function forViewer(ScheduledFollowUp $followUp, User $viewer): self
    {
        $followUp->unsetRelation('members')->unsetRelation('stages')->unsetRelation('events');
        $followUp->load([
            'student',
            'assignedTo.roles',
            'sharedWith.roles',
            'members.user.roles',
            'stages.responsible.roles',
            'stages.closedBy.roles',
            'stages.notes.author.roles',
            'events.actor.roles',
            'events.subjectUser.roles',
        ]);
        foreach ($followUp->stages as $stage) {
            $stage->setRelation('followUp', $followUp);
        }

        $resource = new self($followUp);
        $resource->viewer = $viewer;
        $resource->cutoff = $followUp->memberFor($viewer)?->leftAt();
        if ($resource->cutoff !== null) {
            $resource->truncate($followUp, $viewer, $resource->cutoff);
        }

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $policy = app(ScheduledFollowUpPolicy::class);
        $viewer = $this->viewer;
        $member = $this->memberFor($viewer);
        $isGeneral = $policy->isGeneralResponsible($viewer, $this->resource);
        $mayView = $policy->view($viewer, $this->resource);

        return parent::toArray($request) + [
            'participants' => StaffMemberResource::collection($this->sharedWith),
            'members' => $this->members->map(fn (FollowUpMember $m) => [
                'user' => $m->user ? new StaffMemberResource($m->user) : null,
                'role' => $m->role->value,
                'active' => $m->isActive(),
                'has_pending_stage' => $this->stages->contains(fn (FollowUpStage $s) => $s->isPending() && $s->responsible_id === $m->user_id),
                'withdrawn_at' => $m->withdrawn_at?->toIso8601String(),
                'withdrawal_reason' => $m->withdrawal_reason,
                'removed_at' => $m->removed_at?->toIso8601String(),
                'removed_by_id' => $m->removed_by_id,
                'removal_reason' => $m->removal_reason,
            ])->values(),
            'stages' => $this->stages->map(fn (FollowUpStage $stage) => $this->stage($stage, $policy, $viewer))->values(),
            'events' => $this->events->map(fn (FollowUpEvent $event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'actor' => $event->actor ? new StaffMemberResource($event->actor) : null,
                'subject_user' => $event->subjectUser ? new StaffMemberResource($event->subjectUser) : null,
                'stage_id' => $event->follow_up_stage_id,
                'data' => $event->data,
                'note' => $event->note,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
            'viewer' => [
                'user_id' => $viewer->id,
                'is_member' => $member !== null,
                'active' => (bool) $member?->isActive(),
                'role' => $member?->role->value,
                'left_at' => $this->cutoff?->toIso8601String(),
                'hidden' => $member?->hidden_at !== null,
                'is_general_responsible' => $isGeneral,
            ],
            'can' => [
                'add_stage' => $mayView && $policy->addStage($viewer, $this->resource),
                'add_participant' => $mayView && $policy->addParticipant($viewer, $this->resource),
                'manage_general_responsibles' => $mayView && $policy->addGeneralResponsible($viewer, $this->resource),
                'withdraw' => $policy->withdraw($viewer, $this->resource)->allowed(),
                'withdraw_requires_successor' => $isGeneral && $this->members->filter->isGeneralResponsible()->count() === 1,
                'resolve' => $policy->resolve($viewer, $this->resource)->allowed(),
                'hide' => $member !== null && ! $member->isActive() && $member->hidden_at === null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stage(FollowUpStage $stage, ScheduledFollowUpPolicy $policy, User $viewer): array
    {
        return [
            'id' => $stage->id,
            'parent_stage_id' => $stage->parent_stage_id,
            'description' => $stage->description,
            'responsible' => $stage->responsible ? new StaffMemberResource($stage->responsible) : null,
            'due_date' => $stage->due_date->toDateString(),
            'status' => $stage->status->value,
            'conclusion' => $stage->conclusion,
            'closed_by' => $stage->closedBy ? new StaffMemberResource($stage->closedBy) : null,
            'closed_at' => $stage->closed_at?->toIso8601String(),
            'created_by_id' => $stage->created_by_id,
            'created_at' => $stage->created_at?->toIso8601String(),
            'notes' => $stage->notes->map(fn (FollowUpStageNote $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author ? new StaffMemberResource($note->author) : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->values(),
            'can' => [
                'update' => $policy->updateStage($viewer, $stage),
                'split' => $policy->splitStage($viewer, $stage),
                'close' => $policy->closeStage($viewer, $stage),
                'add_note' => $policy->addStageNote($viewer, $stage),
            ],
        ];
    }

    /**
     * Rewind the in-memory record (never saved) to what a person who left saw
     * at `$cutoff`.
     */
    protected function truncate(ScheduledFollowUp $followUp, User $viewer, Carbon $cutoff): void
    {
        $followUp->setRelation('events', $followUp->events->filter(fn (FollowUpEvent $e) => $e->created_at <= $cutoff)->values());

        $stages = $followUp->stages
            ->filter(fn (FollowUpStage $s) => $s->created_at <= $cutoff && (
                $s->responsible_id === $viewer->id
                || $s->created_by_id === $viewer->id
                || $s->notes->contains('author_id', $viewer->id)
            ))
            ->each(function (FollowUpStage $s) use ($cutoff): void {
                $s->setRelation('notes', $s->notes->filter(fn (FollowUpStageNote $n) => $n->created_at <= $cutoff)->values());
                if ($s->closed_at !== null && $s->closed_at > $cutoff) {
                    $s->status = FollowUpStageStatus::Pending;
                    $s->conclusion = null;
                    $s->closed_at = null;
                    $s->setRelation('closedBy', null);
                }
            });
        $followUp->setRelation('stages', $stages->values());

        if ($followUp->resolved && $followUp->resolved_at > $cutoff) {
            $followUp->resolved = false;
            $followUp->resolved_by_id = null;
            $followUp->resolved_at = null;
            $followUp->resolution_note = null;
        }

        // People who joined after they left are not shown either.
        $followUp->setRelation('members', $followUp->members->filter(fn (FollowUpMember $m) => $m->created_at <= $cutoff)->values());
        $followUp->setRelation('sharedWith', $followUp->sharedWith->filter(
            fn (User $u) => $followUp->members->contains(fn (FollowUpMember $m) => $m->user_id === $u->id && $m->role === FollowUpMemberRole::Participant)
        )->values());
    }
}
