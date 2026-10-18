<?php

namespace App\Services\FollowUps;

use App\Enums\FollowUpEventType;
use App\Enums\FollowUpMemberRole;
use App\Enums\FollowUpStageStatus;
use App\Models\FollowUpEvent;
use App\Models\FollowUpMember;
use App\Models\FollowUpStage;
use App\Models\FollowUpStageNote;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use App\Notifications\FollowUpAssignedNotification;
use Illuminate\Support\Facades\DB;

/**
 * The state changes of a follow-up with stages and several people (ClickUp
 * 86e3qkwna). Authorization and validation are done before reaching here
 * (ScheduledFollowUpPolicy + FormRequests); this class only keeps the
 * invariants and writes the history:
 *
 * - The general deadline (`due_date`) is the latest due date among the
 *   stages that were not subdivided; every change records old and new.
 * - Adding a pending stage to a resolved follow-up reopens it. The previous
 *   conclusion is kept: in the earlier `resolved` event and copied into the
 *   `reopened` one; closed stages are left as they were.
 * - Whoever is made responsible of a stage becomes a participant.
 * - `assigned_to_id` mirrors the first active general responsible.
 *
 * Free text (descriptions, notes, conclusions, reasons) goes to the database
 * only — never to logs or event `data` (CLAUDE.md security rule 11).
 */
class FollowUpWorkflow
{
    /**
     * @param  array{title: string, description: string, due_date: string, assigned_to_id?: int|null, shared_with_ids?: array<int, int>|null}  $data
     */
    public function create(Student $student, User $creator, array $data): ScheduledFollowUp
    {
        $assigneeId = (int) ($data['assigned_to_id'] ?? $creator->id);

        $followUp = DB::transaction(function () use ($student, $creator, $data, $assigneeId): ScheduledFollowUp {
            $followUp = ScheduledFollowUp::create([
                'student_id' => $student->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'due_date' => $data['due_date'],
                'created_by_id' => $creator->id,
                'assigned_to_id' => $assigneeId,
            ]);

            $this->record($followUp, FollowUpEventType::Created, $creator, data: ['due_date' => $followUp->due_date->toDateString()]);

            $this->member($followUp, $assigneeId, FollowUpMemberRole::GeneralResponsible);
            // The creator and the people it is shared with take part too.
            foreach ([$creator->id, ...array_map('intval', $data['shared_with_ids'] ?? [])] as $userId) {
                $this->member($followUp, $userId, FollowUpMemberRole::Participant);
            }

            // The first stage is the creation data itself.
            FollowUpStage::create([
                'school_id' => $followUp->school_id,
                'scheduled_follow_up_id' => $followUp->id,
                'description' => $data['description'],
                'responsible_id' => $assigneeId,
                'due_date' => $data['due_date'],
                'created_by_id' => $creator->id,
            ]);

            return $followUp;
        });

        if ($assigneeId !== $creator->id) {
            User::query()->find($assigneeId)?->notify(new FollowUpAssignedNotification($followUp, $creator));
        }

        return $followUp;
    }

    /**
     * @param  array{description: string, responsible_id: int, due_date: string}  $data
     */
    public function addStage(ScheduledFollowUp $followUp, User $actor, array $data): FollowUpStage
    {
        $stage = DB::transaction(function () use ($followUp, $actor, $data): FollowUpStage {
            $stage = $this->newStage($followUp, $actor, $data);
            $this->record($followUp, FollowUpEventType::StageAdded, $actor, $stage, $stage->responsible_id, [
                'due_date' => $stage->due_date->toDateString(),
            ]);
            $this->reopenIfResolved($followUp, $actor);
            $this->syncDeadline($followUp, $actor);

            return $stage;
        });

        $this->notifyStageResponsible($followUp, $stage, $actor);

        return $stage;
    }

    /**
     * @param  array{description?: string, responsible_id?: int, due_date?: string}  $data
     */
    public function updateStage(FollowUpStage $stage, User $actor, array $data): FollowUpStage
    {
        $followUp = $stage->followUp;
        $previousResponsible = $stage->responsible_id;

        DB::transaction(function () use ($stage, $followUp, $actor, $data, $previousResponsible): void {
            $previousDue = $stage->due_date->toDateString();
            $stage->fill($data)->save();

            // Ids and dates only: the description is sensitive.
            $changes = ['description_changed' => $stage->wasChanged('description')];
            if ($stage->wasChanged('responsible_id')) {
                $changes['responsible_from'] = $previousResponsible;
                $changes['responsible_to'] = $stage->responsible_id;
                $this->member($followUp, $stage->responsible_id, FollowUpMemberRole::Participant);
            }
            if ($stage->wasChanged('due_date')) {
                $changes['due_date_from'] = $previousDue;
                $changes['due_date_to'] = $stage->due_date->toDateString();
            }

            $this->record($followUp, FollowUpEventType::StageUpdated, $actor, $stage, data: $changes);
            $this->syncDeadline($followUp, $actor);
        });

        if ($stage->responsible_id !== $previousResponsible) {
            $this->notifyStageResponsible($followUp, $stage, $actor);
        }

        return $stage;
    }

    /**
     * Subdivide a pending stage: it is marked `split` (kept with its notes)
     * and the new stages point at it.
     *
     * @param  list<array{description: string, responsible_id: int, due_date: string}>  $parts
     * @return list<FollowUpStage>
     */
    public function splitStage(FollowUpStage $stage, User $actor, array $parts): array
    {
        $followUp = $stage->followUp;

        $children = DB::transaction(function () use ($stage, $followUp, $actor, $parts): array {
            $stage->update(['status' => FollowUpStageStatus::Split]);

            $children = array_map(
                fn (array $part) => $this->newStage($followUp, $actor, $part + ['parent_stage_id' => $stage->id]),
                $parts,
            );

            $this->record($followUp, FollowUpEventType::StageSplit, $actor, $stage, data: [
                'into_stage_ids' => array_map(fn (FollowUpStage $child) => $child->id, $children),
            ]);
            $this->syncDeadline($followUp, $actor);

            return $children;
        });

        foreach ($children as $child) {
            $this->notifyStageResponsible($followUp, $child, $actor);
        }

        return $children;
    }

    public function closeStage(FollowUpStage $stage, User $actor, string $conclusion): void
    {
        DB::transaction(function () use ($stage, $actor, $conclusion): void {
            $stage->update([
                'status' => FollowUpStageStatus::Done,
                'conclusion' => $conclusion,
                'closed_by_id' => $actor->id,
                'closed_at' => now(),
            ]);
            $this->record($stage->followUp, FollowUpEventType::StageClosed, $actor, $stage);
        });
    }

    public function addNote(FollowUpStage $stage, User $actor, string $body): FollowUpStageNote
    {
        return FollowUpStageNote::create([
            'school_id' => $stage->school_id,
            'follow_up_stage_id' => $stage->id,
            'author_id' => $actor->id,
            'body' => $body,
        ]);
    }

    public function addParticipant(ScheduledFollowUp $followUp, User $actor, User $user): void
    {
        DB::transaction(function () use ($followUp, $actor, $user): void {
            if ($this->member($followUp, $user->id, FollowUpMemberRole::Participant)) {
                $this->record($followUp, FollowUpEventType::ParticipantAdded, $actor, subjectId: $user->id);
            }
        });
    }

    public function removeParticipant(ScheduledFollowUp $followUp, User $actor, User $user, ?string $reason): void
    {
        DB::transaction(function () use ($followUp, $actor, $user, $reason): void {
            $followUp->memberFor($user)->update([
                'removed_at' => now(),
                'removed_by_id' => $actor->id,
                'removal_reason' => $reason,
            ]);
            $this->record($followUp, FollowUpEventType::ParticipantRemoved, $actor, subjectId: $user->id, note: $reason);
        });
    }

    public function addGeneralResponsible(ScheduledFollowUp $followUp, User $actor, User $user): void
    {
        $added = DB::transaction(function () use ($followUp, $actor, $user): bool {
            if (! $this->member($followUp, $user->id, FollowUpMemberRole::GeneralResponsible)) {
                return false;
            }
            $this->record($followUp, FollowUpEventType::GeneralResponsibleAdded, $actor, subjectId: $user->id);
            $this->syncAssignee($followUp);

            return true;
        });

        if ($added && ! $user->is($actor)) {
            $user->notify(new FollowUpAssignedNotification($followUp, $actor));
        }
    }

    /** Demote a general responsible to participant (they stay involved). */
    public function removeGeneralResponsible(ScheduledFollowUp $followUp, User $actor, User $user): void
    {
        DB::transaction(function () use ($followUp, $actor, $user): void {
            $followUp->memberFor($user)->update(['role' => FollowUpMemberRole::Participant]);
            $this->record($followUp, FollowUpEventType::GeneralResponsibleRemoved, $actor, subjectId: $user->id);
            $this->syncAssignee($followUp);
        });
    }

    /**
     * Leave the follow-up with a reason. When `$successor` is given (required
     * by the request if the actor is the only general responsible) they are
     * made general responsible in the same action, with no acceptance step.
     */
    public function withdraw(ScheduledFollowUp $followUp, User $actor, string $reason, ?User $successor): void
    {
        if ($successor !== null) {
            $this->addGeneralResponsible($followUp, $actor, $successor);
        }

        DB::transaction(function () use ($followUp, $actor, $reason): void {
            $followUp->load('members')->memberFor($actor)->update([
                'withdrawn_at' => now(),
                'withdrawal_reason' => $reason,
            ]);
            $this->record($followUp, FollowUpEventType::Withdrew, $actor, subjectId: $actor->id, note: $reason);
            $this->syncAssignee($followUp);
        });
    }

    public function hide(ScheduledFollowUp $followUp, User $actor): void
    {
        $followUp->memberFor($actor)->update(['hidden_at' => now()]);
    }

    public function resolve(ScheduledFollowUp $followUp, User $actor, string $conclusion): void
    {
        DB::transaction(function () use ($followUp, $actor, $conclusion): void {
            $followUp->update([
                'resolved' => true,
                'resolved_by_id' => $actor->id,
                'resolved_at' => now(),
                'resolution_note' => $conclusion,
            ]);
            $this->record($followUp, FollowUpEventType::Resolved, $actor, note: $conclusion);
        });
    }

    /**
     * @param  array{description: string, responsible_id: int, due_date: string, parent_stage_id?: int}  $data
     */
    protected function newStage(ScheduledFollowUp $followUp, User $actor, array $data): FollowUpStage
    {
        $stage = FollowUpStage::create([
            'school_id' => $followUp->school_id,
            'scheduled_follow_up_id' => $followUp->id,
            'parent_stage_id' => $data['parent_stage_id'] ?? null,
            'description' => $data['description'],
            'responsible_id' => (int) $data['responsible_id'],
            'due_date' => $data['due_date'],
            'created_by_id' => $actor->id,
        ]);
        $this->member($followUp, $stage->responsible_id, FollowUpMemberRole::Participant);

        return $stage;
    }

    /**
     * Make `$userId` an active member with at least `$role`. A general
     * responsible is never downgraded by being made a stage responsible; a
     * person who had left is brought back (and the follow-up shows in their
     * inbox again). Returns whether anything changed.
     */
    protected function member(ScheduledFollowUp $followUp, int $userId, FollowUpMemberRole $role): bool
    {
        $member = FollowUpMember::query()
            ->where('scheduled_follow_up_id', $followUp->id)
            ->where('user_id', $userId)
            ->first();

        if ($member === null) {
            FollowUpMember::create([
                'school_id' => $followUp->school_id,
                'scheduled_follow_up_id' => $followUp->id,
                'user_id' => $userId,
                'role' => $role,
            ]);
            $followUp->unsetRelation('members');

            return true;
        }

        $changes = [];
        if (! $member->isActive()) {
            $changes += ['withdrawn_at' => null, 'removed_at' => null, 'removed_by_id' => null, 'hidden_at' => null];
            // Coming back as a participant, not with the role they left with.
            $changes['role'] = $role;
        } elseif ($role === FollowUpMemberRole::GeneralResponsible && $member->role !== $role) {
            $changes['role'] = $role;
        }

        if ($changes === []) {
            return false;
        }

        $member->update($changes);
        $followUp->unsetRelation('members');

        return true;
    }

    protected function reopenIfResolved(ScheduledFollowUp $followUp, User $actor): void
    {
        if (! $followUp->resolved) {
            return;
        }

        $this->record($followUp, FollowUpEventType::Reopened, $actor, data: [
            'resolved_by_id' => $followUp->resolved_by_id,
            'resolved_at' => $followUp->resolved_at?->toIso8601String(),
        ], note: $followUp->resolution_note);

        $followUp->update([
            'resolved' => false,
            'resolved_by_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
        ]);
    }

    /** General deadline = latest due date among the non-subdivided stages. */
    protected function syncDeadline(ScheduledFollowUp $followUp, User $actor): void
    {
        $latest = FollowUpStage::query()
            ->where('scheduled_follow_up_id', $followUp->id)
            ->where('status', '!=', FollowUpStageStatus::Split->value)
            ->max('due_date');

        $followUp->unsetRelation('stages');
        if ($latest === null) {
            return;
        }

        $from = $followUp->due_date->toDateString();
        $to = substr((string) $latest, 0, 10);
        if ($from === $to) {
            return;
        }

        $followUp->update(['due_date' => $to]);
        $this->record($followUp, FollowUpEventType::DeadlineChanged, $actor, data: ['from' => $from, 'to' => $to]);
    }

    protected function syncAssignee(ScheduledFollowUp $followUp): void
    {
        $first = FollowUpMember::query()
            ->where('scheduled_follow_up_id', $followUp->id)
            ->where('role', FollowUpMemberRole::GeneralResponsible->value)
            ->whereNull('withdrawn_at')
            ->whereNull('removed_at')
            ->orderBy('id')
            ->value('user_id');

        if ($first !== null && $first !== $followUp->assigned_to_id) {
            $followUp->update(['assigned_to_id' => $first]);
        }
        $followUp->unsetRelation('members');
    }

    protected function notifyStageResponsible(ScheduledFollowUp $followUp, FollowUpStage $stage, User $actor): void
    {
        if ($stage->responsible_id !== null && $stage->responsible_id !== $actor->id) {
            User::query()->find($stage->responsible_id)?->notify(new FollowUpAssignedNotification($followUp, $actor, $stage));
        }
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function record(
        ScheduledFollowUp $followUp,
        FollowUpEventType $type,
        User $actor,
        ?FollowUpStage $stage = null,
        ?int $subjectId = null,
        ?array $data = null,
        ?string $note = null,
    ): void {
        FollowUpEvent::create([
            'school_id' => $followUp->school_id,
            'scheduled_follow_up_id' => $followUp->id,
            'follow_up_stage_id' => $stage?->id,
            'actor_id' => $actor->id,
            'subject_user_id' => $subjectId,
            'type' => $type,
            'data' => $data,
            'note' => $note,
            'created_at' => now(),
        ]);
        $followUp->unsetRelation('events');
    }
}
