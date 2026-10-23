<?php

namespace App\Policies;

use App\Enums\FollowUpMemberRole;
use App\Enums\FollowUpStageStatus;
use App\Enums\Role;
use App\Models\FollowUpStage;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for scheduled follow-ups (docs/prompts/17-seguimiento-
 * programado.md §2). Deliberately a NEW policy, not a reuse of
 * AccommodationPolicy: unlike Accommodation::create (which only checks role),
 * this entity requires a real relationship with the student — a teacher may
 * only touch follow-ups on students they actually teach.
 *
 * The follow-up may reference sensitive observations about a minor, so the
 * rule is the same as viewing the student in tracking: same school AND
 * (teaches the student OR a school-wide clinical role). Tenant isolation and
 * the role rule are both checked here (defence in depth over SchoolScope, per
 * CLAUDE.md).
 *
 * Seeing a follow-up is still "whoever sees the student". *Managing* it
 * (ClickUp 86e3qkwna, decided by product) depends on the person's part in it,
 * always on top of still seeing the student:
 *
 * - General responsibles: everything, including adding/removing general
 *   responsibles and resolving (only once no stage is pending).
 * - Stage responsibles: edit, subdivide, annotate and close their own stage;
 *   add stages; add participants/stage responsibles and remove plain
 *   participants. Progress notes and closing are limited to the stage's
 *   responsible and the general responsibles, not every participant.
 * - Plain participants: view, and withdraw (with a reason, no pending stage).
 *
 * Business-rule denials carry a message (Response::deny) so the client can
 * show why, e.g. "pending stages" on resolve/withdraw.
 *
 * The review task of a direction accommodation proposal (ClickUp 86e3qkwkk)
 * is school-wide only — a proposal psychopedagogy has not confirmed never
 * reaches a teacher, not even as a task — and is managed by the proposal
 * circuit (confirm, withdraw, reject close it), never by hand: no resolving,
 * stages or people changes on it.
 */
class ScheduledFollowUpPolicy
{
    /**
     * `teachesStudent` answers memoized for this policy instance (one request
     * at most: the Gate and the detail resource resolve a fresh one).
     *
     * @var array<string, bool>
     */
    protected array $teaches = [];

    public function view(User $user, ScheduledFollowUp $followUp): bool
    {
        return $this->canAccessStudent($user, $followUp->student)
            && (! $followUp->isAccommodationReview() || $user->hasAnyRole(Role::schoolWideValues()));
    }

    /**
     * Authorization for GET /students/{student}/scheduled-follow-ups: there is
     * no single follow-up to check yet, so the same substantive rule is
     * applied at the student level (mirrors AlertPolicy::viewForGroup).
     */
    public function viewForStudent(User $user, Student $student): bool
    {
        return $this->canAccessStudent($user, $student);
    }

    public function create(User $user, Student $student): bool
    {
        return $this->canAccessStudent($user, $student);
    }

    public function resolve(User $user, ScheduledFollowUp $followUp): Response
    {
        if ($followUp->isAccommodationReview()) {
            return Response::deny('This review task closes with the accommodation proposal.');
        }
        if (! $this->canAccessStudent($user, $followUp->student) || ! $this->isGeneralResponsible($user, $followUp)) {
            return Response::deny('Only a general responsible can resolve this follow-up.');
        }
        if ($followUp->resolved) {
            return Response::deny('This follow-up is already resolved.');
        }
        if ($followUp->loadMissing('stages')->hasPendingStages()) {
            return Response::deny('Every stage must be closed before resolving.');
        }

        return Response::allow();
    }

    /** Add a stage: any responsible (general or of some stage). */
    public function addStage(User $user, ScheduledFollowUp $followUp): bool
    {
        return ! $followUp->isAccommodationReview()
            && $this->canAccessStudent($user, $followUp->student) && $this->isResponsible($user, $followUp);
    }

    /** Add participants and stage responsibles: any responsible. */
    public function addParticipant(User $user, ScheduledFollowUp $followUp): bool
    {
        return $this->addStage($user, $followUp);
    }

    /**
     * Remove a plain participant: any responsible. A general responsible is
     * demoted through removeGeneralResponsible instead, someone leaving on
     * their own uses withdraw, and a person with a pending stage must have it
     * reassigned first so no work is orphaned.
     */
    public function removeParticipant(User $user, ScheduledFollowUp $followUp, User $target): Response
    {
        if (! $this->addParticipant($user, $followUp)) {
            return Response::deny();
        }

        $member = $followUp->memberFor($target);
        if ($member === null || ! $member->isActive() || $member->role !== FollowUpMemberRole::Participant || $target->is($user)) {
            return Response::deny('Only an active participant other than yourself can be removed.');
        }
        if ($this->hasPendingStageFor($target, $followUp)) {
            return Response::deny('Reassign this person\'s pending stages before removing them.');
        }

        return Response::allow();
    }

    /** Add (or promote to) a general responsible: only general responsibles. */
    public function addGeneralResponsible(User $user, ScheduledFollowUp $followUp): bool
    {
        return ! $followUp->isAccommodationReview()
            && $this->canAccessStudent($user, $followUp->student) && $this->isGeneralResponsible($user, $followUp);
    }

    /**
     * Demote a general responsible to participant: only general
     * responsibles, and never the last one (the follow-up always has one).
     */
    public function removeGeneralResponsible(User $user, ScheduledFollowUp $followUp, User $target): Response
    {
        if (! $this->addGeneralResponsible($user, $followUp)) {
            return Response::deny();
        }
        if (! $this->isGeneralResponsible($target, $followUp)) {
            return Response::deny('This person is not a general responsible.');
        }
        if ($this->generalResponsibleCount($followUp) < 2) {
            return Response::deny('A follow-up needs at least one general responsible.');
        }

        return Response::allow();
    }

    /**
     * Withdraw: any active member with no pending stage of their own. Being
     * the only general responsible is handled by the request, which then
     * requires designating a replacement in the same action.
     */
    public function withdraw(User $user, ScheduledFollowUp $followUp): Response
    {
        if ($followUp->isAccommodationReview()) {
            return Response::deny('This review task closes with the accommodation proposal.');
        }
        $member = $followUp->loadMissing('members')->memberFor($user);
        if (! $this->canAccessStudent($user, $followUp->student) || $member === null || ! $member->isActive()) {
            return Response::deny('You are not taking part in this follow-up.');
        }
        if ($this->hasPendingStageFor($user, $followUp)) {
            return Response::deny('Close or reassign your pending stages before withdrawing.');
        }

        return Response::allow();
    }

    /** Hide from one's own inbox: only someone who already left it. */
    public function hide(User $user, ScheduledFollowUp $followUp): bool
    {
        $member = $followUp->loadMissing('members')->memberFor($user);

        return $member !== null && ! $member->isActive();
    }

    /** Edit a pending stage: its responsible or a general responsible. */
    public function updateStage(User $user, FollowUpStage $stage): bool
    {
        return $stage->isPending() && $this->canManageStage($user, $stage);
    }

    public function splitStage(User $user, FollowUpStage $stage): bool
    {
        return $this->updateStage($user, $stage);
    }

    public function closeStage(User $user, FollowUpStage $stage): bool
    {
        return $this->updateStage($user, $stage);
    }

    /**
     * Progress notes: same people as editing, also on a closed stage (a
     * clarification after the fact) but not on one that was subdivided.
     */
    public function addStageNote(User $user, FollowUpStage $stage): bool
    {
        return $stage->status !== FollowUpStageStatus::Split && $this->canManageStage($user, $stage);
    }

    public function isGeneralResponsible(User $user, ScheduledFollowUp $followUp): bool
    {
        return (bool) $followUp->loadMissing('members')->memberFor($user)?->isGeneralResponsible();
    }

    /** Active member who is a general responsible or responsible of some stage. */
    public function isResponsible(User $user, ScheduledFollowUp $followUp): bool
    {
        $member = $followUp->loadMissing(['members', 'stages'])->memberFor($user);
        if ($member === null || ! $member->isActive()) {
            return false;
        }

        return $member->role === FollowUpMemberRole::GeneralResponsible
            || $followUp->stages->contains('responsible_id', $user->id);
    }

    protected function canManageStage(User $user, FollowUpStage $stage): bool
    {
        $followUp = $stage->followUp;
        if (! $this->canAccessStudent($user, $followUp->student)) {
            return false;
        }

        $member = $followUp->loadMissing('members')->memberFor($user);
        if ($member === null || ! $member->isActive()) {
            return false;
        }

        return $stage->responsible_id === $user->id || $member->role === FollowUpMemberRole::GeneralResponsible;
    }

    protected function hasPendingStageFor(User $user, ScheduledFollowUp $followUp): bool
    {
        return $followUp->loadMissing('stages')->stages
            ->contains(fn (FollowUpStage $stage) => $stage->isPending() && $stage->responsible_id === $user->id);
    }

    protected function generalResponsibleCount(ScheduledFollowUp $followUp): int
    {
        return $followUp->loadMissing('members')->members->filter->isGeneralResponsible()->count();
    }

    /**
     * Whether `$user` is someone who may see `$student`'s follow-ups, i.e.
     * a valid responsible/co-follower for one. Used to validate the assignee
     * lists and to drop follow-ups from a pending list once the person no
     * longer has access to the student.
     */
    public function canFollow(User $user, Student $student): bool
    {
        return $this->canAccessStudent($user, $student);
    }

    /**
     * Same school AND (teaches the student OR a school-wide role). A teacher
     * only ever reaches their own students; a director/psychopedagogue reaches
     * any student in their own school.
     */
    protected function canAccessStudent(User $user, Student $student): bool
    {
        if ($user->school_id !== $student->school_id) {
            return false;
        }
        // The role check needs no query (roles are loaded once per user), so
        // it goes first; the "teaches" query is remembered per user/student
        // because the detail resource asks for ~5 flags per stage.
        if ($user->hasAnyRole(Role::schoolWideValues())) {
            return true;
        }

        return $this->teaches[$user->id.':'.$student->id] ??= $user->teachesStudent($student);
    }
}
